<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\LLM\Client\Interface\ImageGenerationLLMInterface;
use App\Service\Telegram\Api\TelegramMessageHelper;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Context\TelegramLlmInvocationContext;
use App\Service\Telegram\Persistence\TelegramPersistenceService;

/**
 * Генерує зображення за текстовим промптом (Imagen 4) і надсилає його в чат.
 */
final class GenerateImageTool implements ToolInterface
{
    public function __construct(
        private readonly ImageGenerationLLMInterface $imageGenLlm,
        private readonly TelegramLlmInvocationContext $invocationContext,
        private readonly TelegramService $telegram,
        private readonly TelegramPersistenceService $persistence,
        private readonly string $storageDir,
    ) {}

    public function getName(): ToolName
    {
        return ToolName::GENERATE_IMAGE;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Generate a new image from a text prompt and send it to the current Telegram chat. Use when the user asks to create, draw, or generate a picture. After calling this tool, do not write any reply text — the photo is the reply.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Detailed description of the image to generate (in English for best results).',
                        ],
                    ],
                    'required' => ['prompt'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        if (!$this->invocationContext->isActive()) {
            throw new \RuntimeException('generate_image is only available during a Telegram chat LLM reply.');
        }

        $prompt = isset($arguments['prompt']) && is_string($arguments['prompt'])
            ? trim($arguments['prompt'])
            : '';
        if ($prompt === '') {
            throw new \InvalidArgumentException('prompt is required.');
        }

        if (!$this->imageGenLlm->isConfigured()) {
            throw new \RuntimeException('Image generation LLM is not configured.');
        }

        if (!$this->telegram->isConfigured()) {
            throw new \RuntimeException('Telegram bot is not configured.');
        }

        $telegramMessage = $this->invocationContext->getTelegramMessage();
        $telegramChatId = (int) ($telegramMessage['chat']['id'] ?? 0);
        if ($telegramChatId === 0) {
            throw new \RuntimeException('Cannot resolve Telegram chat id from invocation context.');
        }
        $isGroup = TelegramMessageHelper::isGroup($telegramMessage);

        $this->telegram->sendChatAction($telegramChatId, 'upload_photo');

        $generated = $this->imageGenLlm->generateImage($prompt);
        $localPath = $this->saveGeneratedImage($telegramChatId, $generated->binary, $generated->mimeType);

        $sent = $this->telegram->sendPhoto($telegramChatId, $localPath);
        $this->persistence->recordAgentOutboundFromTelegramSend(
            $sent + ['text' => '[Згенероване зображення: ' . $prompt . ']'],
            $isGroup,
            $this->invocationContext->getInbound(),
        );

        $this->invocationContext->suppressReply();

        return json_encode([
            'ok' => true,
            'sent' => true,
            'file_path' => $localPath,
            'note' => 'Generated image was sent to the chat. Do not write any reply text now.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function saveGeneratedImage(int $telegramChatId, string $binary, string $mimeType): string
    {
        $extension = match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $dir = $this->storageDir . '/' . $telegramChatId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Не вдалося створити директорію "%s".', $dir));
        }

        $path = sprintf('%s/generated_%d.%s', $dir, time(), $extension);
        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException(sprintf('Не вдалося записати файл "%s".', $path));
        }

        return $path;
    }
}
