<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Service\Telegram\TelegramAgentLlmReplySender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_message_group')]
final class ProcessTelegramGroupMessageHandler
{
    public function __construct(
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
    ) {}

    public function __invoke(ProcessTelegramGroupMessage $job): void
    {
        $this->agentLlmReplySender->sendLlmReplyForChat($job->telegramChatId, true, $job->triggerTelegramMessageId);
    }
}
