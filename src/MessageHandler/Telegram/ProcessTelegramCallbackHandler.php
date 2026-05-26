<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\ProcessTelegramCallback;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Callback\Dispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessTelegramCallbackHandler
{
    public function __construct(private readonly Dispatcher $callbackDispatcher) {}

    public function __invoke(ProcessTelegramCallback $job): void
    {
        $callbackQuery = $job->callbackQuery ?? [];
        $dto = CallbackDTO::buildFromArray($callbackQuery);
        if ($dto === null) return;

        $this->callbackDispatcher->dispatch($dto);
    }
}
