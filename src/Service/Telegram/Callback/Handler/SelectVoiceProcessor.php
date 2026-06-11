<?php

declare(strict_types=1);

namespace App\Service\Telegram\Callback\Handler;

use App\Enum\TtsVoice;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Callback\CallbackDTO;
use App\Service\Telegram\Voice\VoiceListResponder;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Обробляє натискання inline-кнопки вибору голосу озвучки (callback_data: tv:{voice}).
 * Зберігає голос під користувачем, який натиснув кнопку.
 */
final class SelectVoiceProcessor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TelegramService $telegram,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function handles(string $data): bool
    {
        return str_starts_with($data, VoiceListResponder::CALLBACK_PREFIX);
    }

    public function process(CallbackDTO $callback): void
    {
        if (!$this->handles($callback->data)) return;

        $voice = TtsVoice::tryFrom(substr($callback->data, strlen(VoiceListResponder::CALLBACK_PREFIX)));
        if ($voice === null) {
            $this->telegram->answerCallbackQuery($callback->callbackId, 'Невідомий голос');

            return;
        }

        try {
            $user = $this->users->upsertFromTelegramFromPayload(['id' => $callback->fromId]);
            $user->setTtsVoice($voice);
            $this->documentManager->flush();

            $this->telegram->answerCallbackQuery($callback->callbackId, 'Голос обрано: ' . $voice->value);

            if ($callback->messageId > 0) {
                $this->telegram->editMessageText(
                    $callback->chatId,
                    $callback->messageId,
                    sprintf('Голос обрано: %s (%s)', $voice->value, $voice->description()),
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Помилка callback вибору голосу: {error}', ['error' => $e->getMessage()], $e);
            if ($callback->callbackId !== '') {
                try {
                    $this->telegram->answerCallbackQuery($callback->callbackId);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }
}
