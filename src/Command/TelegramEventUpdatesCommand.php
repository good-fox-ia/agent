<?php

namespace App\Command;

use App\Service\LLM\LLMInterface;
use App\Service\Telegram\TelegramService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram:event-updates',
    description: 'Long polling Telegram + відповіді через Groq',
)]
final class TelegramEventUpdatesCommand extends Command
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly LLMInterface $llm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Long polling timeout (секунди)', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = max(0, (int) $input->getOption('timeout'));

        if (!$this->telegram->isConfigured()) {
            $io->error('TELEGRAM_BOT_TOKEN is empty');

            return Command::FAILURE;
        }

        if (!$this->llm->isConfigured()) {
            $io->error('GROQ_API_KEY is empty');

            return Command::FAILURE;
        }

        $io->note('Long polling + Groq. Зупинка: Ctrl+C');

        $offset = 0;

        while (true) {
            try {
                $updates = $this->telegram->getUpdates($offset, $timeout);
            } catch (\Throwable $e) {
                $io->error('getUpdates: '.$e->getMessage());
                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                if (!is_array($update)) continue;

                $updateId = (int) ($update['update_id'] ?? 0);
                $offset = max($offset, $updateId + 1);

                $message = $update['message'] ?? $update['edited_message'] ?? null;
                if (!is_array($message)) continue;

                $chatId = $message['chat']['id'] ?? null;
                if ($chatId === null) continue;

                $from = $message['from']['username'] ?? $message['from']['first_name'] ?? '?';
                $text = isset($message['text']) ? trim((string) $message['text']) : '';
                $preview = $text !== '' ? mb_substr($text, 0, 80) : '[не текст]';

                if ($text === '') {
                    try {
                        $this->telegram->sendMessage($chatId, 'Надішліть текстове повідомлення.');
                    } catch (\Throwable $e) {
                        $io->error(sprintf('sendMessage chat=%s: %s', (string) $chatId, $e->getMessage()));
                    }

                    continue;
                }

                try {
                    $answer = $this->llm->complete($text);
                    $answer = mb_substr($answer, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
                    $this->telegram->sendMessage($chatId, $answer);
                    $io->writeln(sprintf(
                        '[%s] chat=%s from=%s preview=%s',
                        date('c'),
                        (string) $chatId,
                        (string) $from,
                        $preview,
                    ));
                } catch (\Throwable $e) {
                    $io->error(sprintf('Groq/sendMessage chat=%s: %s', (string) $chatId, $e->getMessage()));
                    try {
                        $this->telegram->sendMessage(
                            $chatId,
                            mb_substr('Помилка: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                        );
                    } catch (\Throwable) {
                        // ignore secondary failure
                    }
                }
            }
        }
    }
}