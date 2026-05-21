<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Message\Telegram\ProcessTelegramCallbackQuery;
use App\Service\Telegram\Command\SelectChatCallbackProcessor;
use App\Service\Telegram\Command\ShowChatHistoryCallbackProcessor;
use Psr\Log\LoggerInterface;

/**
 * Обробляє callback_query з Telegram (викликається напряму з long polling).
 */
final class TelegramCallbackDispatcher
{
    public function __construct(
        private readonly SelectChatCallbackProcessor $selectChatCallback,
        private readonly ShowChatHistoryCallbackProcessor $showChatHistoryCallback,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function dispatch(array $callbackQuery): void
    {
        $data = (string) ($callbackQuery['data'] ?? '');

        if ($this->selectChatCallback->handles($callbackQuery)) {
            $this->logger->info('Telegram callback: select chat data={data}', ['data' => $data]);
            $this->selectChatCallback->process($callbackQuery);

            return;
        }

        if ($this->showChatHistoryCallback->handles($callbackQuery)) {
            $this->logger->info('Telegram callback: show history data={data}', ['data' => $data]);
            $this->showChatHistoryCallback->process($callbackQuery);

            return;
        }

        $this->logger->warning('Telegram callback не оброблено data={data}', ['data' => $data]);
    }

    /**
     * @deprecated Використовуйте {@see dispatch()}; лишено для сумісності з messenger handler.
     */
    public function handleMessage(ProcessTelegramCallbackQuery $job): void
    {
        $this->dispatch($job->callbackQuery);
    }
}
