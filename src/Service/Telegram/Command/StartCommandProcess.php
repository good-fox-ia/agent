<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Service\Telegram\Agent\WelcomeMessage;

final class StartCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly WelcomeMessage $welcomeMessage,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::START;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $this->welcomeMessage->send($telegramMessage, $inbound);
    }
}
