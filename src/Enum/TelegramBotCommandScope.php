<?php

declare(strict_types=1);

namespace App\Enum;

enum TelegramBotCommandScope: string
{
    case PRIVATE = 'all_private_chats';
    case GROUP = 'all_group_chats';

    /** @return array{type: string} */
    public function toTelegramScope(): array
    {
        return ['type' => $this->value];
    }
}
