<?php

declare(strict_types=1);

namespace App\Service\Telegram\Voice;

use App\Enum\TtsVoice;
use App\Service\LLM\Client\Interface\AudioGenerationLLMInterface;
use App\Service\Telegram\Api\TelegramService;
use Symfony\Component\Process\Process;

/**
 * Озвучує текст відповіді через TTS і надсилає голосове повідомлення в Telegram.
 */
final class VoiceReplySender
{
    /** Telegram voice найкраще працює з OGG/Opus. */
    private const FFMPEG_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly AudioGenerationLLMInterface $tts,
        private readonly TelegramService $telegram,
    ) {}

    public function isAvailable(): bool
    {
        return $this->tts->isConfigured() && $this->telegram->isConfigured();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> sent message payload з Telegram API
     */
    public function sendVoiceReply(int $chatId, string $text, array $options = [], ?TtsVoice $voice = null): array
    {
        $plainText = $this->toPlainText($text);
        if ($plainText === '') {
            throw new \InvalidArgumentException('Text for voice reply is empty.');
        }

        $this->sendChatActionSafely($chatId, 'record_voice');
        // Без обраного голосу провайдер використає свій дефолт (константа в клієнті).
        $audio = $this->tts->generateAudio($plainText, $voice !== null ? ['voice' => $voice->value] : []);

        $oggPath = $this->convertToOggOpus($audio->binary);

        try {
            $this->sendChatActionSafely($chatId, 'upload_voice');

            return $this->telegram->sendVoice($chatId, $oggPath, $options);
        } finally {
            @unlink($oggPath);
        }
    }

    /**
     * Статус у чаті («записує голосове…») — best effort, не має ламати відправку відповіді.
     */
    private function sendChatActionSafely(int $chatId, string $action): void
    {
        try {
            $this->telegram->sendChatAction($chatId, $action);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Прибирає HTML-розмітку Telegram (parse_mode HTML) перед озвучкою.
     */
    private function toPlainText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Конвертує аудіо (WAV від TTS) в OGG/Opus для Telegram sendVoice.
     *
     * @return string шлях до тимчасового .ogg файлу (видаляє caller)
     */
    private function convertToOggOpus(string $audioBinary): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'tts_in_');
        $outputPath = tempnam(sys_get_temp_dir(), 'tts_out_') . '.ogg';

        if ($inputPath === false || file_put_contents($inputPath, $audioBinary) === false) {
            throw new \RuntimeException('Не вдалося записати тимчасовий аудіофайл для конвертації.');
        }

        try {
            $process = new Process([
                'ffmpeg', '-y', '-i', $inputPath,
                '-c:a', 'libopus', '-b:a', '48k', '-ac', '1', '-ar', '48000',
                $outputPath,
            ]);
            $process->setTimeout(self::FFMPEG_TIMEOUT_SECONDS);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException('ffmpeg не зміг конвертувати аудіо в OGG/Opus: ' . trim($process->getErrorOutput()));
            }

            if (!is_file($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('ffmpeg не створив OGG-файл.');
            }

            return $outputPath;
        } catch (\Throwable $e) {
            @unlink($outputPath);

            throw $e;
        } finally {
            @unlink($inputPath);
        }
    }
}
