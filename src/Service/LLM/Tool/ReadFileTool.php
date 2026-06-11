<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Відкриває локальний файл за шляхом, декодує його в читабельний UTF-8 текст
 * (включно з витяганням тексту з PDF) і повертає вміст для перегляду LLM
 * (з обрізанням, щоб не переповнити контекст).
 */
final class ReadFileTool implements ToolInterface
{
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    private const MAX_CONTENT_LENGTH = 20000;

    /** Mime-типи без префікса text/, які теж можна читати як текст. */
    private const TEXTUAL_MIME_TYPES = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-httpd-php',
        'application/x-sh',
        'application/x-yaml',
        'application/yaml',
        'application/csv',
        'application/sql',
        'image/svg+xml',
    ];

    public function getName(): ToolName
    {
        return ToolName::READ_FILE;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Open a local file by its path, decode it to readable UTF-8 text and return the content. Supports text files (txt, json, csv, xml, code, logs etc.) and PDF documents (extracts text). For images use describe_image instead.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Local filesystem path to the file.',
                        ],
                    ],
                    'required' => ['file_path'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $filePath = isset($arguments['file_path']) && is_string($arguments['file_path'])
            ? trim($arguments['file_path'])
            : '';
        if ($filePath === '') {
            throw new \InvalidArgumentException('file_path is required.');
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException(sprintf('File not found or not readable: %s', $filePath));
        }

        $size = filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_SIZE_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'File is too large to read (%s bytes, limit %d): %s',
                $size === false ? 'unknown' : (string) $size,
                self::MAX_FILE_SIZE_BYTES,
                $filePath,
            ));
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            throw new \InvalidArgumentException(sprintf(
                'File is an image (%s), use the describe_image tool instead: %s',
                $mimeType,
                $filePath,
            ));
        }

        $binary = file_get_contents($filePath);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Failed to read file: %s', $filePath));
        }

        $content = $mimeType === 'application/pdf'
            ? $this->extractPdfText($binary, $filePath)
            : $this->decodeToUtf8Text($binary, $mimeType, $filePath);

        $truncated = mb_strlen($content) > self::MAX_CONTENT_LENGTH;
        if ($truncated) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);
        }

        return json_encode([
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'content' => $content,
            'truncated' => $truncated,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function extractPdfText(string $binary, string $filePath): string
    {
        try {
            $document = new PdfParser()->parseContent($binary);
            $text = $document->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to extract text from PDF %s: %s', $filePath, $e->getMessage()), previous: $e);
        }

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*(\n\s*)+/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            throw new \RuntimeException(sprintf('PDF contains no extractable text (can be a scanned document): %s', $filePath));
        }

        return $text;
    }

    private function decodeToUtf8Text(string $binary, string $mimeType, string $filePath): string
    {
        if (!$this->isTextualMime($mimeType) && !$this->looksLikeText($binary)) {
            throw new \InvalidArgumentException(sprintf(
                'File is binary (%s) and cannot be decoded to text: %s',
                $mimeType,
                $filePath,
            ));
        }

        if (mb_check_encoding($binary, 'UTF-8')) {
            return $this->stripUtf8Bom($binary);
        }

        $encoding = mb_detect_encoding($binary, ['UTF-8', 'Windows-1251', 'KOI8-U', 'ISO-8859-1', 'UTF-16LE', 'UTF-16BE'], true);
        if ($encoding === false) {
            $encoding = 'Windows-1251';
        }

        return $this->stripUtf8Bom(mb_convert_encoding($binary, 'UTF-8', $encoding));
    }

    private function isTextualMime(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, self::TEXTUAL_MIME_TYPES, true)
            || str_ends_with($mimeType, '+json')
            || str_ends_with($mimeType, '+xml');
    }

    /** Евристика для файлів з generic mime (application/octet-stream): без NUL-байтів — вважаємо текстом. */
    private function looksLikeText(string $binary): bool
    {
        return !str_contains($binary, "\0");
    }

    private function stripUtf8Bom(string $text): string
    {
        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }
}
