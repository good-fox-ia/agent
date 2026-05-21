<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\ProcessTelegramCallbackQuery;
use App\Service\Telegram\TelegramCallbackDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessTelegramCallbackQueryHandler
{
    public function __construct(
        private readonly TelegramCallbackDispatcher $callbackDispatcher,
    ) {}

    public function __invoke(ProcessTelegramCallbackQuery $job): void
    {
        $this->callbackDispatcher->handleMessage($job);
    }
}
