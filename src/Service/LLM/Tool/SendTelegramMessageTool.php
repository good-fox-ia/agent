<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Repository\UserRepository;
use App\Service\Telegram\TelegramPersistenceService;
use App\Service\Telegram\TelegramService;

final class SendTelegramMessageTool implements ToolInterface
{
    private const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TelegramService $telegram,
        private readonly TelegramPersistenceService $persistence,
    ) {}

    public function getName(): ToolName
    {
        return ToolName::SEND_TELEGRAM_MESSAGE;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Send a private Telegram message to a user by their @username. The user must have previously messaged the bot. Username may be passed with or without @.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'username' => [
                            'type' => 'string',
                            'description' => 'Telegram username of the recipient, e.g. johndoe or @johndoe.',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Text of the message to send.',
                        ],
                    ],
                    'required' => ['username', 'message'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $username = isset($arguments['username']) && is_string($arguments['username'])
            ? trim($arguments['username'])
            : '';
        $message = isset($arguments['message']) && is_string($arguments['message'])
            ? trim($arguments['message'])
            : '';

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required.');
        }

        if (!$this->telegram->isConfigured()) {
            throw new \RuntimeException('Telegram bot is not configured.');
        }

        $user = $this->users->findOneByUsername($username);
        if ($user === null) {
            throw new \InvalidArgumentException(sprintf('User not found: %s', $username));
        }

        $text = mb_substr($message, 0, self::TELEGRAM_MAX_MESSAGE_LENGTH);

        try {
            $sent = $this->telegram->sendMessage($user->getTelegramUserId(), $text);
            $this->persistence->recordAgentOutboundFromTelegramSend($sent, false, null);
        } catch (\Throwable $e) {
            return json_encode([
                'ok' => false,
                'username' => $user->getUsername(),
                'telegram_user_id' => $user->getTelegramUserId(),
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'ok' => true,
            'username' => $user->getUsername(),
            'telegram_user_id' => $user->getTelegramUserId(),
            'message_id' => $sent['message_id'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}
