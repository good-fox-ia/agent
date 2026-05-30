<?php

declare(strict_types=1);

namespace App\Service\Telegram\Api;

use App\Enum\TelegramBotCommand;
use App\Enum\TelegramBotCommandScope;
use Psr\Log\LoggerInterface;

/**
 * Реєструє стандартні команди бота в меню Telegram (setMyCommands).
 */
final class TelegramBotCommandsRegistrar
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(): void
    {
        if (!$this->telegram->isConfigured()) {
            $this->logger->warning('Telegram bot commands: TELEGRAM_BOT_TOKEN is empty, skip registration');

            return;
        }

        foreach (TelegramBotCommandScope::cases() as $scope) {
            $commands = TelegramBotCommand::forTelegramMenu($scope);
            if ($commands === []) {
                $this->logger->warning('Telegram bot commands: порожній список для scope={scope}, пропуск', [
                    'scope' => $scope->value,
                ]);

                continue;
            }

            $this->telegram->setMyCommands($commands, $scope);

            $this->logger->info('Telegram bot commands registered', [
                'scope' => $scope->value,
                'count' => count($commands),
                'commands' => array_column($commands, 'command'),
            ]);
        }
    }
}
