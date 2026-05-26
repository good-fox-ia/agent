<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\TelegramMessageHelper;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\UserMessageSender;
use Psr\Log\LoggerInterface;

final class KeyboardToggleCommandProcess implements CommandProcessInterface
{
    private const ON_TEXT = 'Клавіатуру увімкнено.';

    private const OFF_TEXT = 'Клавіатуру вимкнено.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::KEYBOARD_ON
            || $command === TelegramBotCommand::KEYBOARD_OFF;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $command = TelegramMessageHelper::parseBotCommand($telegramMessage);
        if ($command === null) {
            return;
        }

        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $enable = $command === TelegramBotCommand::KEYBOARD_ON;
            $user->setActiveKeyboard($enable);

            $text = $enable ? self::ON_TEXT : self::OFF_TEXT;
            $sent = $this->messageSender->sendToUser($user, $text);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, false, $inbound);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка зміни клавіатури chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
