<?php

declare(strict_types=1);

namespace App\Service\Telegram\Friends\UI;

use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

final class FriendListResponder
{
    public function __construct(
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(User $user, int $telegramChatId, ?Message $inbound, bool $isGroup): void
    {
        $friends = $this->sortedFriends($user);
        if ($friends === []) {
            $this->sendPlain(
                $user,
                $telegramChatId,
                $inbound,
                $isGroup,
                "У вас ще немає друзів.\n\nДодати: <code>/addfriend @нік</code>",
            );

            return;
        }

        $lines = [];
        foreach ($friends as $friend) {
            $lines[] = $this->formatFriendLine($friend);
        }

        $text = "👥 <b>Ваші друзі</b>\n\n" . implode("\n", $lines);
        $this->sendPlain($user, $telegramChatId, $inbound, $isGroup, $text);
    }

    /**
     * @return list<User>
     */
    private function sortedFriends(User $user): array
    {
        $friends = array_values(array_filter(
            $user->getFriends()->toArray(),
            static fn (mixed $u): bool => $u instanceof User,
        ));

        usort($friends, static function (User $a, User $b): int {
            $ua = mb_strtolower($a->getUsername() ?? '');
            $ub = mb_strtolower($b->getUsername() ?? '');

            return $ua <=> $ub;
        });

        return $friends;
    }

    private function formatFriendLine(User $friend): string
    {
        $username = $friend->getUsername();
        $handle = $username !== null && trim($username) !== '' ? '@' . ltrim(trim($username), '@') : '(no username)';

        $name = trim(($friend->getFirstName() ?? '') . ' ' . ($friend->getLastName() ?? ''));
        if ($name === '') {
            $name = '—';
        }

        return sprintf('• <code>%s</code> — %s', htmlspecialchars($handle), htmlspecialchars($name));
    }

    private function sendPlain(
        User $user,
        int $telegramChatId,
        ?Message $inbound,
        bool $isGroup,
        string $text,
    ): void {
        try {
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, $text, true)
                : $this->messageSender->sendToUser($user, $text);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка списку друзів chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

