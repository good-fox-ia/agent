<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Chat;
use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Persistence\ActiveChatService;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

final class EditSystemPromptCommandProcess implements CommandProcessInterface
{
    private const CUSTOM_PROMPT_MAX_LENGTH = 8000;

    public function __construct(
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly ActiveChatService $activeChat,
        private readonly TelegramService $telegram,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::EDIT_SYSTEM_PROMPT;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, edit_system_promt пропущено chat={chat}', ['chat' => $chatId]);

            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
            $logicalChat = $isGroup
                ? $this->activeChat->ensureForGroup(
                    $this->groups->upsertFromTelegramChatPayload($telegramMessage['chat'])->addUser($user),
                )
                : $this->activeChat->ensureForUser($user);

            $args = TelegramMessageHelper::commandArguments($telegramMessage);
            $replyText = $this->applyArguments($logicalChat, $args);

            $this->documentManager->flush();

            $sent = $isGroup
                ? $this->messageSender->send($chatId, $replyText, true)
                : $this->messageSender->sendToUser($user, $replyText);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound, $logicalChat);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка edit_system_promt chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function applyArguments(Chat $chat, string $args): string
    {
        $trimmed = trim($args);

        if ($trimmed === '') {
            return $this->buildStatusMessage($chat);
        }

        if (in_array(mb_strtolower($trimmed), ['reset', 'скинути', 'clear', 'очистити'], true)) {
            $chat->setSystemPrompt(null);

            return '✅ System prompt для активної бесіди скинуто. Використовуються стандартні інструкції бота.';
        }

        if (mb_strlen($trimmed) > self::CUSTOM_PROMPT_MAX_LENGTH) {
            $trimmed = mb_substr($trimmed, 0, self::CUSTOM_PROMPT_MAX_LENGTH);
        }

        $chat->setSystemPrompt($trimmed);

        return sprintf(
            "✅ System prompt для активної бесіди оновлено (%d символів).\n\nПревʼю:\n%s",
            mb_strlen($trimmed),
            $this->truncateForTelegram($trimmed, 500),
        );
    }

    private function buildStatusMessage(Chat $chat): string
    {
        $custom = $chat->getSystemPrompt();

        if ($custom === null || trim($custom) === '') {
            return <<<'TEXT'
ℹ️ Для активної бесіди використовується стандартний system prompt бота.

Щоб задати свій:
<code>/edit_system_promt ваш текст інструкцій...</code>

Щоб скинути:
<code>/edit_system_promt reset</code>
TEXT;
        }

        return sprintf(
            "ℹ️ Поточний system prompt активної бесіди (%d символів):\n\n%s\n\nЩоб змінити — надішліть команду з новим текстом.\nЩоб скинути — <code>/edit_system_promt reset</code>",
            mb_strlen(trim($custom)),
            $this->truncateForTelegram(trim($custom), 3000),
        );
    }

    private function truncateForTelegram(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1).'…';
    }
}
