<?php

declare(strict_types=1);

namespace App\Service\Telegram\UI;

use App\Document\User;
use App\Repository\UserRepository;
use App\Service\Telegram\Api\TelegramService;
use App\Service\Telegram\Keyboard\UserReplyMarkupResolver;

/**
 * Надсилання повідомлень у приватний чат з урахуванням налаштування reply-клавіатури користувача.
 */
final class UserMessageSender
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly UserRepository $users,
        private readonly UserReplyMarkupResolver $replyMarkup,
    ) {}

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sendToUser(User $user, string $text, array $options = []): array
    {
        return $this->telegram->sendMessage(
            $user->getTelegramUserId(),
            $text,
            $this->replyMarkup->applyForUser(
                $user,
                $this->buildOptions($options)),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function send(int $chatId, string $text, bool $isGroup, array $options = [], ?User $user = null): array
    {
        $options = $this->buildOptions($options);
        if (!$isGroup) {
            $user ??= $this->users->findOneByTelegramUserId($chatId);
            if ($user !== null) {
                $options = $this->replyMarkup->applyForUser($user, $options);
            }
        }

        return $this->telegram->sendMessage($chatId, $text, $options);
    }

    /**
     * Надсилає URL з увімкненим link preview (вбудований плеєр Telegram, як у звичайному чаті).
     *
     * @return array<string, mixed>
     */
    public function sendUrlWithPreview(int $chatId, string $url, bool $isGroup, ?string $caption = null): array
    {
        $text = $caption !== null && $caption !== '' ? $caption."\n".$url : $url;

        $options = $this->buildOptions([
            'link_preview_options' => [
                'is_disabled' => false,
                'url' => $url,
                'prefer_large_media' => true,
                'show_above_text' => true,
            ],
        ]);

        if (!$isGroup) {
            $user = $this->users->findOneByTelegramUserId($chatId);
            if ($user !== null) {
                $options = $this->replyMarkup->applyForUser($user, $options);
            }
        }

        return $this->telegram->sendMessage($chatId, $text, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildOptions(array $options): array
    {
        return $options + ['parse_mode' => 'HTML'];
    }
}

