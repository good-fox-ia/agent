<?php

declare(strict_types=1);

namespace App\Service\Telegram\Payment;

use App\Document\Message;
use App\Document\User;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Надсилає inline-клавіатуру вибору суми поповнення (callback_data: tp:{amount}).
 */
final class TopupResponder
{
    public const CALLBACK_PREFIX = 'tp:';

    public function __construct(
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(User $user, int $telegramChatId, ?Message $inbound, bool $isGroup): void
    {
        $buttons = [];
        foreach (StarsAmountValidator::PRESET_AMOUNTS as $amount) {
            $buttons[] = [
                'text' => $amount . ' ⭐',
                'callback_data' => self::CALLBACK_PREFIX . $amount,
            ];
        }

        $keyboard = array_chunk($buttons, 2);
        $text = "Обери суму поповнення:\n\nАбо надішли: <code>/topup 25</code>";

        try {
            $options = [
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ];
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, $text, true, $options)
                : $this->messageSender->sendToUser($user, $text, $options);

            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка надсилання topup keyboard chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
