<?php

declare(strict_types=1);

namespace App\Service\Telegram\Keyboard;

use App\Document\User;

final class UserReplyMarkupResolver
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function applyForUser(User $user, array $options = []): array
    {
        if (isset($options['reply_markup']['inline_keyboard'])) {
            return $options;
        }

        $options['reply_markup'] = $user->isActiveKeyboard()
            ? ReplyKeyboard::markup()
            : ['remove_keyboard' => true];

        return $options;
    }
}
