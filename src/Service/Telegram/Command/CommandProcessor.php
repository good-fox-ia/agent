<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Service\Telegram\Api\TelegramMessageHelper;
use Psr\Log\LoggerInterface;

/**
 * Визначає команду бота з повідомлення та делегує обробку відповідному процесу.
 */
final class CommandProcessor
{
    /** @param iterable<CommandProcessInterface> $processes */
    public function __construct(
        private readonly iterable $processes,
        private readonly LoggerInterface $logger,
    ) {}

    public function tryProcess(array $telegramMessage, ?Message $inbound): bool
    {
        $command = TelegramMessageHelper::parseBotCommand($telegramMessage);
        if ($command === null) {
            return false;
        }

        return $this->process($command, $telegramMessage, $inbound);
    }

    public function process(TelegramBotCommand $command, array $telegramMessage, ?Message $inbound): bool
    {
        $scope = TelegramMessageHelper::commandScope($telegramMessage);
        if (!$command->isAvailableIn($scope)) {
            $this->logger->info('Telegram command /{command} недоступна в scope={scope} chat={chat}', [
                'command' => $command->value,
                'scope' => $scope->value,
                'chat' => (string) ($telegramMessage['chat']['id'] ?? ''),
            ]);

            return true;
        }

        foreach ($this->processes as $process) {
            if (!$process->handles($command)) {
                continue;
            }

            $process->onProcess($telegramMessage, $inbound);
            $this->logger->info('Telegram command /{command} оброблено chat={chat}', [
                'command' => $command->value,
                'chat' => (string) ($telegramMessage['chat']['id'] ?? ''),
            ]);

            return true;
        }

        return false;
    }
}
