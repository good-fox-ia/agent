<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\LLM\Client\Interface\ImageDescriptionLLMInterface;

/**
 * Описує зображення за шляхом до локального файла через vision LLM.
 */
final class DescribeImageTool implements ToolInterface
{
    private const DEFAULT_PROMPT = 'Опиши, що зображено на цьому фото. Відповідай українською мовою, звичайним текстом без розмітки, коротко і по суті.';

    public function __construct(private readonly ImageDescriptionLLMInterface $imageLlm) {}

    public function getName(): ToolName
    {
        return ToolName::DESCRIBE_IMAGE;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Describe an image stored on disk using a vision LLM. Pass the local file path of the image; optionally pass a custom question about the image.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'file_path' => [
                            'type' => 'string',
                            'description' => 'Local filesystem path to the image file.',
                        ],
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Optional question or instruction about the image. Defaults to a general description request.',
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

        $mimeType = mime_content_type($filePath) ?: '';
        if (!str_starts_with($mimeType, 'image/')) {
            throw new \InvalidArgumentException(sprintf('File is not an image (%s): %s', $mimeType !== '' ? $mimeType : 'unknown mime', $filePath));
        }

        if (!$this->imageLlm->isConfigured()) {
            throw new \RuntimeException('Vision LLM is not configured.');
        }

        $binary = file_get_contents($filePath);
        if ($binary === false) {
            throw new \RuntimeException(sprintf('Failed to read file: %s', $filePath));
        }

        $prompt = isset($arguments['prompt']) && is_string($arguments['prompt']) && trim($arguments['prompt']) !== ''
            ? trim($arguments['prompt'])
            : self::DEFAULT_PROMPT;

        $description = trim($this->imageLlm->describeImage($binary, $mimeType, $prompt));
        if ($description === '') {
            throw new \RuntimeException('Vision LLM returned an empty description.');
        }

        return json_encode([
            'file_path' => $filePath,
            'description' => $description,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
