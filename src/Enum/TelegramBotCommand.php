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
    case VOICE_ON = 'voiceon';
    case VOICE_OFF = 'voiceoff';
    case LIST_CHATS = 'listchats';
    case EDIT_SYSTEM_PROMPT = 'edit_system_promt';
    case FRIENDS = 'friends';
    case ADD_FRIEND = 'addfriend';

    public function asSlash(): string
    {
        return '/' . $this->value;
    }

    /** Короткий опис для меню команд Telegram (setMyCommands). */
    public function menuDescription(): string
    {
        return match ($this) {
            self::START => 'Початок роботи та привітання',
            self::HELP => 'Довідка та підказки',
            self::NEW_CHAT => 'Нова бесіда',
            self::KEYBOARD_ON => 'Увімкнути клавіатуру внизу',
            self::KEYBOARD_OFF => 'Вимкнути клавіатуру внизу',
            self::VOICE_ON => 'Увімкнути відповіді голосом',
            self::VOICE_OFF => 'Вимкнути відповіді голосом',
            self::LIST_CHATS => 'Список збережених бесід',
            self::EDIT_SYSTEM_PROMPT => 'System prompt активної бесіди',
            self::FRIENDS => 'Список друзів',
            self::ADD_FRIEND => 'Додати друга за @нікнеймом',
        };
    }

    public function isAvailableIn(TelegramBotCommandScope $scope): bool
    {
        return match ($this) {
            self::START => $scope === TelegramBotCommandScope::PRIVATE,
            self::KEYBOARD_ON => $scope === TelegramBotCommandScope::PRIVATE,
            self::KEYBOARD_OFF => $scope === TelegramBotCommandScope::PRIVATE,
            self::LIST_CHATS => $scope === TelegramBotCommandScope::PRIVATE,
            self::FRIENDS => $scope === TelegramBotCommandScope::PRIVATE,
            self::ADD_FRIEND => $scope === TelegramBotCommandScope::PRIVATE,
            self::HELP => $scope === TelegramBotCommandScope::PRIVATE,
            self::NEW_CHAT => $scope === TelegramBotCommandScope::PRIVATE,
            self::VOICE_ON => true,
            self::VOICE_OFF => true,
            self::EDIT_SYSTEM_PROMPT => true,
        };
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    public static function forTelegramMenu(TelegramBotCommandScope $scope): array
    {
        return array_map(
            static fn (self $command) => [
                'command' => $command->value,
                'description' => $command->menuDescription(),
            ],
            array_values(array_filter(
                self::cases(),
                static fn (self $command) => $command->isAvailableIn($scope),
            )),
        );
    }
}
