<?php

declare(strict_types=1);

namespace App\Enum;


enum MessageType: string
{
    case UserPrivate = 'user_private';
    case UserGroup = 'user_group';
    case AgentPrivate = 'agent_private';
    case AgentGroup = 'agent_group';
}
