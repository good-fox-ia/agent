<?php

declare(strict_types=1);

namespace App\Service\Telegram\Keyboard;

use App\Enum\TelegramBotCommand;

final class ReplyKeyboard
{
    /**
     * @return array<string, mixed>
     */
    public static function markup(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => TelegramBotCommand::NEW_CHAT->asSlash()],
                    ['text' => TelegramBotCommand::HELP->asSlash()],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }
}
