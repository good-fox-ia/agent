<?php

declare(strict_types=1);

namespace App\Enum;

enum ToolName: string
{
    case GET_WEATHER = 'get_weather';
    case GET_CURRENT_TIME = 'get_current_time';
    case FETCH_WEB_PAGE = 'fetch_web_page';
    case WEB_SEARCH = 'web_search';
    case DESCRIBE_IMAGE = 'describe_image';
    case READ_FILE = 'read_file';
    case DO_NOTHING = 'do_nothing';
    case ASK_USER_QUESTION = 'ask_user_question';
    case SEND_TELEGRAM_MESSAGE = 'send_telegram_message';
    case TELEGRAM_COMMAND_START = 'telegram_command_start';
    case TELEGRAM_COMMAND_HELP = 'telegram_command_help';
    case TELEGRAM_COMMAND_NEW_CHAT = 'telegram_command_new_chat';
    case TELEGRAM_COMMAND_KEYBOARD_ON = 'telegram_command_keyboard_on';
    case TELEGRAM_COMMAND_KEYBOARD_OFF = 'telegram_command_keyboard_off';
    case TELEGRAM_COMMAND_VOICE_ON = 'telegram_command_voice_on';
    case TELEGRAM_COMMAND_VOICE_OFF = 'telegram_command_voice_off';
    case TELEGRAM_COMMAND_VOICE = 'telegram_command_voice';
    case TELEGRAM_COMMAND_LIST_CHATS = 'telegram_command_list_chats';
    case TELEGRAM_COMMAND_EDIT_SYSTEM_PROMPT = 'telegram_command_edit_system_prompt';
    case TELEGRAM_COMMAND_FRIENDS = 'telegram_command_friends';
    case TELEGRAM_COMMAND_ADD_FRIEND = 'telegram_command_add_friend';
}
