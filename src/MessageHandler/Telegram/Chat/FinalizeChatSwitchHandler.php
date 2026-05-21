<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram\Chat;

use App\Message\Telegram\Chat\FinalizeChatSwitch;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Telegram\Chat\ChatSwitchFinalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'telegram_messages')]
final class FinalizeChatSwitchHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatRepository $chats,
        private readonly ChatSwitchFinalizer $finalizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FinalizeChatSwitch $job): void
    {
        $user = $this->users->findOneByTelegramUserId($job->telegramUserId);
        if ($user === null) {
            $this->logger->warning('FinalizeChatSwitch: user not found telegram_user_id={id}', [
                'id' => $job->telegramUserId,
            ]);

            return;
        }

        $chat = $this->chats->findOneByIdForUser($job->logicalChatId, $user);
        if ($chat === null) {
            $this->logger->warning('FinalizeChatSwitch: chat not found chat_id={id}', [
                'id' => $job->logicalChatId,
            ]);

            return;
        }

        $this->finalizer->finalize(
            $user,
            $chat,
            $job->telegramChatId,
            $job->placeholderTelegramMessageId,
            $job->isGroup,
        );
    }
}
