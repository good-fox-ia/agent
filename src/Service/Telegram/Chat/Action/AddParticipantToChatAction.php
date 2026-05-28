<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat\Action;

use App\Document\Chat;
use App\Document\User;
use App\Repository\UserRepository;
use App\Service\Telegram\Chat\SharedChatHelper;
use App\Service\Telegram\Chat\UI\ChatListResponder;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

final class AddParticipantToChatAction
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SharedChatHelper $sharedChat,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function addByUsername(User $inviter, string $username): string
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return "Потрібно передати нікнейм.\nПриклад: <code>/addtochat @john</code>";
        }

        $chat = $inviter->getCurrentChat();
        if ($chat === null) {
            return 'Спочатку оберіть або створіть бесіду (<code>/newchat</code>).';
        }

        if ($chat->getGroup() !== null) {
            return 'Додавати учасників можна лише в приватну бесіду, не в груповий чат Telegram.';
        }

        if ($this->sharedChat->countParticipants($chat) >= 2) {
            return 'У цій бесіді вже є співрозмовник. Створіть нову бесіду (<code>/newchat</code>) для іншого спільного чату.';
        }

        $guest = $this->users->findOneByUsername($username);
        if ($guest === null) {
            return sprintf(
                'Користувача не знайдено: <code>@%s</code>. Він має хоча б раз написати боту.',
                htmlspecialchars($username),
            );
        }

        if ($guest->getId() === $inviter->getId()) {
            return 'Не можна додати себе до власної бесіди.';
        }

        if ($chat->getUsers()->contains($guest)) {
            return 'Цей користувач вже в бесіді.';
        }

        $chat->addUser($guest);
        $guest->addChat($chat);
        $inviter->addFriend($guest);
        $chat->setTitle($this->buildSharedTitle($inviter, $guest));

        $this->documentManager->flush();

        $this->notifyGuest($inviter, $guest, $chat);

        return sprintf(
            '✅ <b>%s</b> додано до спільної бесіди. Коли перейде в чат — побачить ваші повідомлення.',
            htmlspecialchars($this->sharedChat->formatUserLabel($guest)),
        );
    }

    private function buildSharedTitle(User $a, User $b): string
    {
        return sprintf(
            'Спільний: %s & %s',
            $this->sharedChat->formatUserDisplayName($a),
            $this->sharedChat->formatUserDisplayName($b),
        );
    }

    private function notifyGuest(User $inviter, User $guest, Chat $chat): void
    {
        if ($chat->getId() === null) {
            return;
        }

        $inviterLabel = htmlspecialchars($this->sharedChat->formatUserLabel($inviter));
        $text = sprintf(
            "👋 Вас додали до <b>спільного чату</b> з %s.\n\nНатисніть кнопку нижче, щоб перейти в бесіду. Після цього повідомлення обох учасників і відповіді асистента будуть синхронізовані.",
            $inviterLabel,
        );

        try {
            $sent = $this->messageSender->sendToUser($guest, $text, [
                'reply_markup' => [
                    'inline_keyboard' => [[[
                        'text' => 'Перейти в спільний чат',
                        'callback_data' => ChatListResponder::CALLBACK_PREFIX . $chat->getId(),
                    ]]],
                ],
            ]);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, false, null, $guest->getCurrentChat());
        } catch (\Throwable $e) {
            $this->logger->error('Не вдалося сповістити запрошеного user={user}: {error}', [
                'user' => $guest->getTelegramUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
