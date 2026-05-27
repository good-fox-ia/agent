<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Content;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Service\Telegram\Api\TelegramMessageHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(fromTransport: 'telegram_messages')]
final class ProcessTelegramTextMessageHandler
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    public function __invoke(ProcessTelegramTextMessage $message): void
    {
        $payload = $message->message ?? [];
        
        if (TelegramMessageHelper::visibleTextBody($payload) === '') return;

        $chatId = (int) ($payload['chat']['id'] ?? null);
        $messageId = (int) ($payload['message_id'] ?? null);
        if ($chatId === null || $messageId === null) return;

        if (TelegramMessageHelper::isGroup($payload)) {
            $this->bus->dispatch(new ProcessTelegramGroupMessage($chatId, $messageId, $payload));
        } else {
            $this->bus->dispatch(new ProcessTelegramPrivateMessage($chatId, $messageId, $payload));
        }
    }
}
