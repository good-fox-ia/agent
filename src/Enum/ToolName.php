<?php

declare(strict_types=1);

namespace App\Enum;

enum ToolName: string
{
    case GET_WEATHER = 'get_weather';
    case GET_CURRENT_TIME = 'get_current_time';
    case SEND_TELEGRAM_MESSAGE = 'send_telegram_message';
}
