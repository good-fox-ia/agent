<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Service\Telegram\TelegramAgentLlmReplySender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_message_private')]
final class ProcessTelegramPrivateMessageHandler
{
    public function __construct(
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
    ) {}

    public function __invoke(ProcessTelegramPrivateMessage $job): void
    {
        $this->agentLlmReplySender->sendLlmReplyForChat($job->telegramChatId, false, $job->triggerTelegramMessageId);
    }
}
