<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\ProcessTelegramGroupMessage;
use App\Repository\MessageRepository;
use App\Service\Telegram\Agent\TelegramAgentLlmReplySender;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Chat\GroupBotAddressChecker;
use App\Service\Telegram\Video\TelegramSocialVideoHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_message_group')]
final class ProcessTelegramGroupMessageHandler
{
    private const GROUP_BOT_MENTION_REPLACEMENT = '(відмітиле тебе потрібна твоя відповідь на повідомлення)';

    public function __construct(
        private readonly TelegramAgentLlmReplySender $agentLlmReplySender,
        private readonly TelegramSocialVideoHandler $socialVideoHandler,
        private readonly GroupBotAddressChecker $botAddressChecker,
        private readonly MessageRepository $messages,
        private readonly LoggerInterface $logger,
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

        $text = TelegramMessageHelper::visibleTextBody($job->telegramMessage);
        if ($text === '') return;

        $telegramMessage = $job->telegramMessage;
        $shouldRespond = false;

        if ($this->botAddressChecker->hasBotMention($job->telegramMessage)) {
            $text = str_ireplace(GroupBotAddressChecker::BOT_MENTION, self::GROUP_BOT_MENTION_REPLACEMENT, $text);
            $telegramMessage = TelegramMessageHelper::withVisibleTextBody($telegramMessage, $text);
            try {
                $this->messages->saveInboundTextAfterTranscription(
                    $job->telegramChatId,
                    $job->triggerTelegramMessageId,
                    $text,
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Оновлення Message (згадка бота): {error}', ['error' => $e->getMessage()]);
            }
            $shouldRespond = true;
        } elseif ($this->botAddressChecker->isReplyToBotMessage($job->telegramChatId, $job->telegramMessage)) {
            $shouldRespond = true;
        }

        if (!$shouldRespond) {
            return;
        }

        $this->agentLlmReplySender->sendLlmReplyForChat(
            $job->telegramChatId,
            true,
            $job->triggerTelegramMessageId,
            $telegramMessage,
        );
    }
}
