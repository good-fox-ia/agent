<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Friends\UI\FriendListResponder;
use App\Service\Telegram\Api\TelegramService;
use Psr\Log\LoggerInterface;

final class FriendsCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly FriendListResponder $friendListResponder,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::FRIENDS;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;
        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, friends пропущено chat={chat}', ['chat' => $chatId]);

            return;
        }

        $user = $this->users->upsertFromTelegramFromPayload($from);
        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        $this->friendListResponder->send($user, $chatId, $inbound, $isGroup);
    }
}

