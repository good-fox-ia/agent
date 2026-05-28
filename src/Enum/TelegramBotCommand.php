<?php

declare(strict_types=1);

namespace App\Enum;

enum TelegramBotCommand: string
{
    case START = 'start';
    case HELP = 'help';
    case NEW_CHAT = 'newchat';
    case KEYBOARD_ON = 'keyboardon';
    case KEYBOARD_OFF = 'keyboardoff';
    case LIST_CHATS = 'listchats';
    case EDIT_SYSTEM_PROMPT = 'edit_system_promt';
    case FRIENDS = 'friends';
    case ADD_FRIEND = 'addfriend';
    case ADD_TO_CHAT = 'addtochat';

    public function asSlash(): string
    {
        return '/' . $this->value;
    }
}
