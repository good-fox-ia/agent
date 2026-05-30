<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Telegram\ProcessTelegramCallback;
use App\Message\Telegram\ProcessTelegramMessage;
use App\Service\Telegram\Callback\Dispatcher;
use App\Service\Telegram\Api\TelegramBotCommandsRegistrar;
use App\Service\Telegram\Api\TelegramService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:telegram:event-updates',
    description: 'Long polling Telegram; вхідні оновлення — у чергу telegram_inbound',
)]
final class TelegramEventUpdatesCommand extends Command
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramBotCommandsRegistrar $commandsRegistrar,
        private readonly MessageBusInterface $bus,
        private readonly Dispatcher $callbackDispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Long polling timeout (секунди)', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = max(0, (int) $input->getOption('timeout'));

        if (!$this->telegram->isConfigured()) {
            $io->error('TELEGRAM_BOT_TOKEN is empty');

            return Command::FAILURE;
        }

        $io->note('Long polling. Зупинка: Ctrl+C');

        try {
            $this->commandsRegistrar->register();
            $io->writeln('Команди бота зареєстровано в Telegram');
        } catch (\Throwable $e) {
            $io->warning('Не вдалося зареєструвати команди: ' . $e->getMessage());
        }

        $offset = 0;
        while (true) {
            try {
                $updates = $this->telegram->getUpdates($offset, $timeout);
            } catch (\Throwable $e) {
                $io->error('getUpdates: ' . $e->getMessage());
                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                if (!is_array($update)) continue;

                $updateId = (int) ($update['update_id'] ?? 0);
                $offset = max($offset, $updateId + 1);

                $callback = $update['callback_query'] ?? null;
                if (is_array($callback))
                    $this->bus->dispatch(new ProcessTelegramCallback($callback));

                $message = $update['message'] ?? $update['edited_message'] ?? null;
                if (is_array($message) && isset($message['chat']['id']))
                    $this->bus->dispatch(new ProcessTelegramMessage($message));
            }
        }
    }
}
