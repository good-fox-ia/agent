<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Chat\ChatListPresenter;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramService;
use Psr\Log\LoggerInterface;

final class ListChatsCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ChatListPresenter $chatListPresenter,
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::LIST_CHATS;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            $this->logger->error('Telegram не налаштований, список бесід пропущено chat={chat}', ['chat' => $chatId]);

            return;
        }

        $user = $this->users->upsertFromTelegramFromPayload($from);
        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
        $this->chatListPresenter->send($user, $chatId, $inbound, $isGroup);
    }
}
