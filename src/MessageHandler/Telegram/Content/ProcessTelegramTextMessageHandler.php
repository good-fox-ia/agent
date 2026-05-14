<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Service\Telegram\TelegramMessageHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_messages')]
final class ProcessTelegramTextMessageHandler
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(ProcessTelegramTextMessage $message): void
    {
        $payload = $message->message;
        if (TelegramMessageHelper::visibleTextBody($payload) === '') {
            return;
        }

        $chatId = (int) ($payload['chat']['id'] ?? 0);
        $messageId = (int) ($payload['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) {
            return;
        }

        $isGroup = TelegramMessageHelper::isGroup($payload);

        if ($isGroup) {
            $this->bus->dispatch(new ProcessTelegramGroupMessage($chatId, $messageId));
        } else {
            $this->bus->dispatch(new ProcessTelegramPrivateMessage($chatId, $messageId));
        }
    }
}
