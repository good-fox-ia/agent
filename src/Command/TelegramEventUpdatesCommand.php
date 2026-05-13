<?php

namespace App\Command;

use App\Document\Group;
use App\Document\Message;
use App\Document\TelegramMessageType;
use App\Document\User;
use App\Service\LLM\LLMInterface;
use App\Service\Telegram\TelegramService;
use Doctrine\ODM\MongoDB\DocumentManager;
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
        private readonly DocumentManager $dm,
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

                $this->persistTelegramUserAndGroup($message, $io);

                $chatType = (string) ($message['chat']['type'] ?? 'private');
                $isGroup = in_array($chatType, ['group', 'supergroup'], true);

                $storedInbound = $this->persistInboundUserMessage($message, $isGroup, $io);

                $fromPayload = $message['from'] ?? null;
                $from = is_array($fromPayload)
                    ? (string) ($fromPayload['username'] ?? $fromPayload['first_name'] ?? '?')
                    : '?';
                $text = isset($message['text']) ? trim((string) $message['text']) : '';

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
                    try {
                        $meta = $this->telegram->getFile($fileId);
                        $filePath = $meta['file_path'] ?? null;
                        if (!is_string($filePath) || $filePath === '') throw new \RuntimeException('Telegram getFile: missing file_path.');

                        $binary = $this->telegram->downloadFile($filePath);
                        $transcript = $this->llm->transcribeAudio($binary, $audioFilename, ['language' => 'uk']);
                        if ($transcript === '') {
                            $sent = $this->telegram->sendMessage($chatId, 'Не вдалося розпізнати мову. Спробуйте ще раз голосом.');
                            $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
                            continue;
                        }

                        if ($storedInbound !== null) {
                            $storedInbound->setText($transcript);
                            try {
                                $this->dm->flush();
                            } catch (\Throwable $e) {
                                $io->warning(sprintf('Оновлення Message (транскрипт): %s', $e->getMessage()));
                            }
                        }

                        $prompt = "Користувач надіслав голосове повідомлення. Розпізнаний текст:\n\n".$transcript."\n\nВідповідай стисло українською.";
                        $answer = $this->llm->complete($prompt);
                        $answer = mb_substr($answer, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
                        $sent = $this->telegram->sendMessage($chatId, $answer);
                        $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
                        $io->writeln(sprintf(
                            '[%s] chat=%s from=%s voice transcript=%s',
                            date('c'),
                            (string) $chatId,
                            (string) $from,
                            mb_substr($transcript, 0, 80),
                        ));
                    } catch (\Throwable $e) {
                        $io->error(sprintf('voice/audio chat=%s: %s', (string) $chatId, $e->getMessage()));
                        try {
                            $sent = $this->telegram->sendMessage(
                                $chatId,
                                mb_substr('Помилка обробки аудіо: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                            );
                            $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
                        } catch (\Throwable) {
                        }
                    }

                    continue;
                }

                if ($isGroup) {
                    continue;
                }

                $preview = $text !== '' ? mb_substr($text, 0, 80) : '[не текст]';

                if ($text === '') {
                    try {
                        $sent = $this->telegram->sendMessage($chatId, 'Надішліть текстове або голосове повідомлення.');
                        $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
                    } catch (\Throwable $e) {
                        $io->error(sprintf('sendMessage chat=%s: %s', (string) $chatId, $e->getMessage()));
                    }

                    continue;
                }

                try {
                    $answer = $this->llm->complete($text);
                    $answer = mb_substr($answer, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);
                    $sent = $this->telegram->sendMessage($chatId, $answer);
                    $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
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
                        $sent = $this->telegram->sendMessage(
                            $chatId,
                            mb_substr('Помилка: '.$e->getMessage(), 0, self::TELEGRAM_MAX_MESSAGE_LENGTH),
                        );
                        $this->persistOutboundAgentMessageFromSent($sent, $isGroup, $storedInbound, $io);
                    } catch (\Throwable) {
                        // ignore secondary failure
                    }
                }
            }
        }
    }

    private function persistTelegramUserAndGroup(array $message, SymfonyStyle $io): void
    {
        $chatPayload = $message['chat'] ?? null;
        if (!is_array($chatPayload) || !isset($chatPayload['id'])) {
            return;
        }

        try {
            $telegramChatId = (int) $chatPayload['id'];
            $groupRepo = $this->dm->getRepository(Group::class);
            $group = $groupRepo->findOneBy(['telegramChatId' => $telegramChatId]) ?? new Group($telegramChatId);
            $group->applyFromTelegramPayload($chatPayload);
            $this->dm->persist($group);

            $fromPayload = $message['from'] ?? null;
            if (is_array($fromPayload) && isset($fromPayload['id'])) {
                $telegramUserId = (int) $fromPayload['id'];
                $userRepo = $this->dm->getRepository(User::class);
                $user = $userRepo->findOneBy(['telegramUserId' => $telegramUserId]) ?? new User($telegramUserId);
                $user->applyFromTelegramPayload($fromPayload);
                $this->dm->persist($user);
                $group->addUser($user);
            }

            $this->dm->flush();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Збереження User/Group у MongoDB: %s', $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function persistInboundUserMessage(array $message, bool $isGroup, SymfonyStyle $io): ?Message
    {
        try {
            if (!isset($message['chat']['id'], $message['message_id'])) {
                return null;
            }

            $telegramChatId = (int) $message['chat']['id'];
            $telegramMessageId = (int) $message['message_id'];
            $type = $isGroup ? TelegramMessageType::UserGroup : TelegramMessageType::UserPrivate;

            $repo = $this->dm->getRepository(Message::class);
            $entity = $repo->findOneBy([
                'telegramChatId' => $telegramChatId,
                'telegramMessageId' => $telegramMessageId,
            ]);

            if ($entity === null) {
                $entity = new Message($telegramChatId, $telegramMessageId, $type);
            } else {
                $entity->setType($type);
            }

            $body = $this->extractTelegramMessageText($message);
            $entity->setText($body !== '' ? $body : null);

            $entity->setReplyTo($this->resolveReplyToMessage($telegramChatId, $message));

            $fromPayload = $message['from'] ?? null;
            if (is_array($fromPayload) && isset($fromPayload['id'])) {
                $author = $this->dm->getRepository(User::class)->findOneBy(['telegramUserId' => (int) $fromPayload['id']]);
                $entity->setAuthor($author);
            } else {
                $entity->setAuthor(null);
            }

            if ($isGroup) {
                $entity->setGroup($this->dm->getRepository(Group::class)->findOneBy(['telegramChatId' => $telegramChatId]));
            } else {
                $entity->setGroup(null);
            }

            $this->dm->persist($entity);
            $this->dm->flush();

            return $entity;
        } catch (\Throwable $e) {
            $io->warning(sprintf('Збереження Message (вхідне): %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * @param array<string, mixed> $sent Результат sendMessage (об'єкт повідомлення з Telegram API).
     */
    private function persistOutboundAgentMessageFromSent(array $sent, bool $isGroup, ?Message $replyToInbound, SymfonyStyle $io): void
    {
        try {
            if (!isset($sent['chat']['id'], $sent['message_id'])) {
                return;
            }

            $telegramChatId = (int) $sent['chat']['id'];
            $telegramMessageId = (int) $sent['message_id'];
            $type = $isGroup ? TelegramMessageType::AgentGroup : TelegramMessageType::AgentPrivate;

            $repo = $this->dm->getRepository(Message::class);
            if ($repo->findOneBy(['telegramChatId' => $telegramChatId, 'telegramMessageId' => $telegramMessageId]) !== null) {
                return;
            }

            $entity = new Message($telegramChatId, $telegramMessageId, $type);
            $entity->setAuthor(null);
            $text = isset($sent['text']) ? trim((string) $sent['text']) : '';
            $entity->setText($text !== '' ? $text : null);
            $entity->setReplyTo($replyToInbound);

            if ($isGroup) {
                $entity->setGroup($this->dm->getRepository(Group::class)->findOneBy(['telegramChatId' => $telegramChatId]));
            } else {
                $entity->setGroup(null);
            }

            $this->dm->persist($entity);
            $this->dm->flush();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Збереження Message (агент): %s', $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractTelegramMessageText(array $message): string
    {
        if (isset($message['text'])) {
            return trim((string) $message['text']);
        }
        if (isset($message['caption'])) {
            return trim((string) $message['caption']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function resolveReplyToMessage(int $telegramChatId, array $message): ?Message
    {
        $rt = $message['reply_to_message'] ?? null;
        if (!is_array($rt) || !isset($rt['message_id'])) {
            return null;
        }

        return $this->dm->getRepository(Message::class)->findOneBy([
            'telegramChatId' => $telegramChatId,
            'telegramMessageId' => (int) $rt['message_id'],
        ]);
    }
}
