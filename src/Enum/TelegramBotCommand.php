<?php

declare(strict_types=1);

namespace App\Enum;

enum TelegramBotCommand: string
{
    case START = 'start';
    case HELP = 'help';
}
