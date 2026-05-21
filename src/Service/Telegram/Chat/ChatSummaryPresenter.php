<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Document\Chat;
use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;
use App\Service\Telegram\TelegramUserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Надсилає LLM-опис активної бесіди з кнопкою «Показати все листування».
 */
final class ChatSummaryPresenter
{
    public const HISTORY_CALLBACK_PREFIX = 'sh:';

    private const SHOW_HISTORY_BUTTON = 'Показати все листування';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramUserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(
        User $user,
        Chat $chat,
        string $summary,
        int $telegramChatId,
        ?Message $inbound,
        bool $isGroup,
    ): int {
        if (!$this->telegram->isConfigured()) {
            return 0;
        }

        $text = $this->formatSummaryText($chat, $summary);

        $options = [
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => self::SHOW_HISTORY_BUTTON,
                        'callback_data' => self::HISTORY_CALLBACK_PREFIX . $chat->getId(),
                    ],
                ]],
            ],
        ];

        try {
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, $text, true, $options)
                : $this->messageSender->sendToUser($user, $text, $options);

            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound, $chat);

            return (int) ($sent['message_id'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка надсилання опису бесіди chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function edit(
        User $user,
        Chat $chat,
        string $summary,
        int $telegramChatId,
        int $telegramMessageId,
        bool $isGroup,
    ): void {
        if (!$this->telegram->isConfigured() || $telegramMessageId <= 0) {
            return;
        }

        $text = $this->formatSummaryText($chat, $summary);
        $options = [
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => self::SHOW_HISTORY_BUTTON,
                        'callback_data' => self::HISTORY_CALLBACK_PREFIX . $chat->getId(),
                    ],
                ]],
            ],
        ];

        try {
            $this->telegram->editMessageText($telegramChatId, $telegramMessageId, $text, $options);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка оновлення опису бесіди chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatSummaryText(Chat $chat, string $summary): string
    {
        $title = $chat->getTitle() ?? 'Бесіда';

        return "✅ Активна бесіда\n{$title}\n\n{$summary}";
    }
}
