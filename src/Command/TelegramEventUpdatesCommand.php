<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Telegram\ProcessTelegramMessage;
use App\Service\Telegram\TelegramCallbackDispatcher;
use App\Service\Telegram\TelegramService;
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
        private readonly MessageBusInterface $bus,
        private readonly TelegramCallbackDispatcher $callbackDispatcher,
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
                if (!is_array($update)) {
                    continue;
                }

                $updateId = (int) ($update['update_id'] ?? 0);
                $offset = max($offset, $updateId + 1);

                $callbackQuery = $update['callback_query'] ?? null;
                if (is_array($callbackQuery)) {
                    $data = (string) ($callbackQuery['data'] ?? '');
                    try {
                        $this->callbackDispatcher->dispatch($callbackQuery);
                        $io->writeln(sprintf('[%s] callback OK data=%s', date('c'), $data));
                    } catch (\Throwable $e) {
                        $io->error(sprintf('[%s] callback FAIL data=%s: %s', date('c'), $data, $e->getMessage()));
                    }

                    continue;
                }

                $message = $update['message'] ?? $update['edited_message'] ?? null;
                if (!is_array($message) || !isset($message['chat']['id'])) {
                    continue;
                }

                $this->bus->dispatch(new ProcessTelegramMessage($message));
                $io->writeln(sprintf('[%s] queued inbound chat=%s', date('c'), (string) $message['chat']['id']));
            }
        }
    }
}
