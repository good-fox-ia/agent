<?php

declare(strict_types=1);

namespace App\Service\Telegram\Voice;

use App\Document\Message;
use App\Document\User;
use App\Enum\TtsVoice;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Psr\Log\LoggerInterface;

/**
 * Надсилає inline-клавіатуру вибору голосу озвучки (callback_data: tv:{voice}).
 */
final class VoiceListResponder
{
    public const CALLBACK_PREFIX = 'tv:';

    private const VOICES_PER_ROW = 2;

    public function __construct(
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(User $user, int $telegramChatId, ?Message $inbound, bool $isGroup): void
    {
        $current = $user->getTtsVoice();

        $buttons = [];
        foreach (TtsVoice::cases() as $voice) {
            $prefix = $voice === $current ? '✓ ' : '';
            $buttons[] = [
                'text' => $prefix . $voice->buttonLabel(),
                'callback_data' => self::CALLBACK_PREFIX . $voice->value,
            ];
        }

        $keyboard = array_chunk($buttons, self::VOICES_PER_ROW);

        try {
            $options = [
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ];
            $sent = $isGroup
                ? $this->messageSender->send($telegramChatId, 'Оберіть голос озвучки:', true, $options)
                : $this->messageSender->sendToUser($user, 'Оберіть голос озвучки:', $options);

            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка надсилання списку голосів chat={chat}: {error}', [
                'chat' => $telegramChatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
