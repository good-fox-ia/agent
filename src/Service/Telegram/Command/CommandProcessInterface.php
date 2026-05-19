<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;

interface CommandProcessInterface
{
    public function handles(TelegramBotCommand $command): bool;

    public function onProcess(array $telegramMessage, ?Message $inbound): void;
}
