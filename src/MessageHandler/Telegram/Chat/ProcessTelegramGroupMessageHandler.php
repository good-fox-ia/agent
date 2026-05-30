<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Service\Telegram\Agent\TelegramAgentLlmReplySender;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Video\TelegramSocialVideoHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_message_group')]
final class ProcessTelegramGroupMessageHandler
{
    public function __construct(
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
        private readonly TelegramSocialVideoHandler $socialVideoHandler,
    ) {}

    public function __invoke(ProcessTelegramGroupMessage $job): void
    {
        if ($this->socialVideoHandler->tryHandle(
            $job->telegramChatId,
            $job->triggerTelegramMessageId,
            $job->telegramMessage,
            true,
        )) {
            return;
        }

        // TODO: тимчасовий хардкод — відповідати лише на питання
        $text = TelegramMessageHelper::visibleTextBody($job->telegramMessage);
        if ($text === '' || !str_ends_with($text, '?')) return;

        $this->agentLlmReplySender->sendLlmReplyForChat(
            $job->telegramChatId,
            true,
            $job->triggerTelegramMessageId,
            $job->telegramMessage,
        );
    }
}
