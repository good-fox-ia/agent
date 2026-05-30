<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Telegram\Api\TelegramBotCommandsRegistrar;
use App\Service\Telegram\Api\TelegramService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram:set-commands',
    description: 'Реєструє стандартні команди бота в меню Telegram',
)]
final class TelegramSetCommandsCommand extends Command
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramBotCommandsRegistrar $commandsRegistrar,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->telegram->isConfigured()) {
            $io->error('TELEGRAM_BOT_TOKEN is empty');

            return Command::FAILURE;
        }

        $this->commandsRegistrar->register();
        $io->success('Команди бота зареєстровано в Telegram');

        return Command::SUCCESS;
    }
}
