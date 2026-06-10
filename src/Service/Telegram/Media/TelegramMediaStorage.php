<?php

declare(strict_types=1);

namespace App\Service\Telegram\Media;

use App\Service\Telegram\Api\TelegramService;

/**
 * Завантаження вкладень (фото/відео/документ) з Telegram і збереження на диск сервера.
 */
final class TelegramMediaStorage
{
    /** Ліміт Bot API на завантаження файлів — 20 МБ. */
    public const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly string $storageDir,
    ) {}

    /**
     * Повертає локальний шлях до збереженого файлу або null, якщо файл завеликий (>= 20 МБ).
     */
    public function downloadAndStore(
        int $telegramChatId,
        int $telegramMessageId,
        string $fileId,
        ?int $fileSize,
        ?string $fileName,
    ): ?string {
        if ($fileSize !== null && $fileSize >= self::MAX_FILE_SIZE_BYTES) {
            return null;
        }

        $meta = $this->telegram->getFile($fileId);

        $remotePath = $meta['file_path'] ?? null;
        if (!is_string($remotePath) || $remotePath === '') {
            throw new \RuntimeException('Telegram getFile: missing file_path.');
        }

        $metaSize = isset($meta['file_size']) ? (int) $meta['file_size'] : null;
        if ($metaSize !== null && $metaSize >= self::MAX_FILE_SIZE_BYTES) {
            return null;
        }

        $binary = $this->telegram->downloadFile($remotePath);
        if (strlen($binary) >= self::MAX_FILE_SIZE_BYTES) {
            return null;
        }

        $dir = $this->storageDir . '/' . $telegramChatId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Не вдалося створити директорію "%s".', $dir));
        }

        $localPath = $dir . '/' . $telegramMessageId . '_' . $this->safeFileName($fileName, $remotePath);
        if (file_put_contents($localPath, $binary) === false) {
            throw new \RuntimeException(sprintf('Не вдалося записати файл "%s".', $localPath));
        }

        return $localPath;
    }

    private function safeFileName(?string $fileName, string $remotePath): string
    {
        $name = $fileName ?? basename($remotePath);
        $name = (string) preg_replace('/[^\w.\-]+/u', '_', $name);

        return $name !== '' ? $name : 'file.bin';
    }
}
