<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Telegram\Content\ProcessTelegramAudioMessage;
use App\Message\Telegram\Content\ProcessTelegramTextMessage;
use App\Service\Telegram\TelegramInboundUpdateApplier;
use App\Service\Telegram\TelegramService;
use App\Telegram\TelegramUpdatePayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:telegram:event-updates',
    description: 'Long polling Telegram; повідомлення в черги Messenger, відповіді — воркерами',
)]
final class TelegramEventUpdatesCommand extends Command
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MessageBusInterface $bus,
        private readonly TelegramInboundUpdateApplier $inboundUpdateApplier,
        private readonly LoggerInterface $logger,
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

        $io->note('Long polling. Відповіді обробляють воркери: messenger:consume telegram_messages telegram_audio telegram_message_private telegram_message_group. Зупинка: Ctrl+C');

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
                if (!is_array($update)) {
                    continue;
                }

                $updateId = (int) ($update['update_id'] ?? 0);
                $offset = max($offset, $updateId + 1);

                $message = $update['message'] ?? $update['edited_message'] ?? null;
                if (!is_array($message)) {
                    continue;
                }

                $chatId = $message['chat']['id'] ?? null;
                if ($chatId === null) {
                    continue;
                }

                $this->inboundUpdateApplier->syncParticipantsFromTelegramMessage($message);

                $chatType = (string) ($message['chat']['type'] ?? 'private');
                $isGroup = in_array($chatType, ['group', 'supergroup'], true);

                $storedInbound = $this->inboundUpdateApplier->recordInboundUserMessage($message, $isGroup);

                $fromPayload = $message['from'] ?? null;
                $from = is_array($fromPayload)
                    ? (string) ($fromPayload['username'] ?? $fromPayload['first_name'] ?? '?')
                    : '?';

                $fileId = null;
                $audioFilename = 'voice.ogg';
                if (isset($message['voice']['file_id'])) {
                    $fileId = (string) $message['voice']['file_id'];
                } elseif (isset($message['audio']['file_id'])) {
                    $fileId = (string) $message['audio']['file_id'];
                    $fn = $message['audio']['file_name'] ?? null;
                    $audioFilename = is_string($fn) && $fn !== '' ? basename($fn) : 'audio.ogg';
                }

                if ($fileId !== null) {
                    $this->bus->dispatch(new ProcessTelegramAudioMessage($message, $fileId, $audioFilename));
                    $io->writeln(sprintf('[%s] queued audio chat=%s from=%s', date('c'), (string) $chatId, $from));

                    continue;
                }

                $textBody = TelegramUpdatePayload::visibleTextBody($message);

                if ($textBody !== '') {
                    $this->bus->dispatch(new ProcessTelegramTextMessage($message));
                    $io->writeln(sprintf(
                        '[%s] queued text chat=%s from=%s preview=%s',
                        date('c'),
                        (string) $chatId,
                        $from,
                        mb_substr($textBody, 0, 80),
                    ));

                    continue;
                }

                if ($isGroup) {
                    continue;
                }

                try {
                    $sent = $this->telegram->sendMessage($chatId, 'Надішліть текстове або голосове повідомлення.');
                    $this->inboundUpdateApplier->recordAgentOutboundFromTelegramSend($sent, $isGroup, $storedInbound);
                } catch (\Throwable $e) {
                    $this->logger->error('sendMessage chat={chat}: {error}', ['chat' => (string) $chatId, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
