<?php

declare(strict_types=1);

namespace App\Enum;

enum TelegramBotCommand: string
{
    case START = 'start';
    case HELP = 'help';
    case NEW_CHAT = 'newchat';

    public function asSlash(): string
    {
        return '/' . $this->value;
    }
}
