<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Document\User;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

final class AddFriendCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::ADD_FRIEND;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, addfriend пропущено chat={chat}', ['chat' => $chatId]);

            return;
        }

        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        $user = $this->users->upsertFromTelegramFromPayload($from);

        try {
            $args = TelegramMessageHelper::commandArguments($telegramMessage);
            $username = ltrim(trim($args), '@');
            if ($username === '') {
                $this->sendReply($user, $chatId, $inbound, $isGroup, "Потрібно передати нікнейм.\nПриклад: <code>/addfriend @john</code>");

                return;
            }

            $friend = $this->users->findOneByUsername($username);
            if ($friend === null) {
                $this->sendReply($user, $chatId, $inbound, $isGroup, sprintf('Користувача не знайдено: <code>@%s</code>. Він має хоча б раз написати боту.', htmlspecialchars($username)));

                return;
            }

            $before = $user->getFriends()->contains($friend);
            $user->addFriend($friend);
            $this->documentManager->flush();

            $this->sendReply(
                $user,
                $chatId,
                $inbound,
                $isGroup,
                $before ? 'ℹ️ Цей користувач вже є у ваших друзях.' : '✅ Друга додано.',
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка addfriend chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendReply(User $user, int $chatId, ?Message $inbound, bool $isGroup, string $text): void
    {
        try {
            $sent = $isGroup
                ? $this->messageSender->send($chatId, $text, true)
                : $this->messageSender->sendToUser($user, $text);

            $this->persistence->recordAgentOutboundFromTelegramSend(
                $sent,
                $isGroup,
                $inbound,
                $user->getCurrentChat(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Помилка відповіді addfriend chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

