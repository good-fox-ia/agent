<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback;

use App\Service\Telegram\Callback\Handler\HistoryProcessor;
use App\Service\Telegram\Callback\Handler\SelectChatProcessor;
use App\Service\Telegram\Callback\Handler\SelectVoiceProcessor;
use Psr\Log\LoggerInterface;


final class Dispatcher
{
    public function __construct(
        private readonly SelectChatProcessor $selectChat,
        private readonly HistoryProcessor $history,
        private readonly SelectVoiceProcessor $selectVoice,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(CallbackDTO $callback): void
    {
        if ($this->selectChat->handles($callback->data)) {
            $this->logger->info('Telegram callback: select chat data={data}', ['data' => $callback->data]);
            $this->selectChat->process($callback);

            return;
        }

        if ($this->selectVoice->handles($callback->data)) {
            $this->logger->info('Telegram callback: select voice data={data}', ['data' => $callback->data]);
            $this->selectVoice->process($callback);

            return;
        }

        if ($this->history->handles($callback->data)) {
            $this->logger->info('Telegram callback: show history data={data}', ['data' => $callback->data]);
            $this->history->process($callback);

            return;
        }

        $this->logger->warning('Telegram callback не оброблено data={data}', ['data' => $callback->data]);
    }
}
