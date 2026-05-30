<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\ProcessTelegramPrivateMessage;
use App\Service\Telegram\Agent\TelegramAgentLlmReplySender;
use App\Service\Telegram\Video\TelegramSocialVideoHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_message_private')]
final class ProcessTelegramPrivateMessageHandler
{
    public function __construct(
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
        private readonly TelegramSocialVideoHandler $socialVideoHandler,
    ) {}

    public function __invoke(ProcessTelegramPrivateMessage $job): void
    {
        if ($this->socialVideoHandler->tryHandle(
            $job->telegramChatId,
            $job->triggerTelegramMessageId,
            $job->telegramMessage,
        )) {
            return;
        }

        $this->agentLlmReplySender->sendLlmReplyForChat(
            $job->telegramChatId,
            false,
            $job->triggerTelegramMessageId,
            $job->telegramMessage,
        );
    }
}
