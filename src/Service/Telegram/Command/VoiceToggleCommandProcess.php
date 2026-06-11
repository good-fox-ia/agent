<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Persistence\TelegramPersistenceService;
use App\Service\Telegram\UI\UserMessageSender;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Вмикає/вимикає відповіді бота голосом (TTS) для конкретного користувача.
 */
final class VoiceToggleCommandProcess implements CommandProcessInterface
{
    private const ON_TEXT = 'Відповіді голосом увімкнено. Бот озвучуватиме свої відповіді.';

    private const OFF_TEXT = 'Відповіді голосом вимкнено.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserMessageSender $messageSender,
        private readonly TelegramPersistenceService $persistence,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::VOICE_ON
            || $command === TelegramBotCommand::VOICE_OFF;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $command = TelegramMessageHelper::parseBotCommand($telegramMessage);
        if ($command === null) {
            return;
        }

        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $enable = $command === TelegramBotCommand::VOICE_ON;
            $user->setVoiceReply($enable);
            $this->documentManager->flush();

            $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
            $text = $enable ? self::ON_TEXT : self::OFF_TEXT;
            $sent = $this->messageSender->send($chatId, $text, $isGroup, [], $isGroup ? null : $user);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, $isGroup, $inbound);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка зміни голосових відповідей chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
