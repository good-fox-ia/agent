<?php

declare(strict_types=1);

namespace App\Service\Telegram\Command;

use App\Document\Message;
use App\Enum\TelegramBotCommand;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Voice\VoiceListResponder;
use Psr\Log\LoggerInterface;

/**
 * Команда /voice: показує inline-клавіатуру вибору голосу озвучки.
 */
final class VoiceSelectCommandProcess implements CommandProcessInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly VoiceListResponder $voiceList,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(TelegramBotCommand $command): bool
    {
        return $command === TelegramBotCommand::VOICE;
    }

    public function onProcess(array $telegramMessage, ?Message $inbound): void
    {
        $chatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        $from = $telegramMessage['from'] ?? null;

        if ($chatId === 0 || !is_array($from) || !isset($from['id'])) {
            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload($from);
            $isGroup = TelegramMessageHelper::isGroup($telegramMessage);
            $this->voiceList->send($user, $chatId, $inbound, $isGroup);
        } catch (\Throwable $e) {
            $this->logger->error('Помилка команди /voice chat={chat}: {error}', [
                'chat' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
