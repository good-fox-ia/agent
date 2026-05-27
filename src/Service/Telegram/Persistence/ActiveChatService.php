<?php

declare(strict_types=1);

namespace App\Service\Telegram\Persistence;

use App\Document\Chat;
use App\Document\Group;
use App\Document\User;
use App\Repository\ChatRepository;

/**
 * Забезпечує активну бесіду (логічний Chat) для користувача або групи перед збереженням повідомлень і викликом LLM.
 */
final class ActiveChatService
{
    public function __construct(
        private readonly ChatRepository $chats,
    ) {}

    public function ensureForUser(User $user): Chat
    {
        $current = $user->getCurrentChat();
        if ($current !== null) {
            return $current;
        }

        return $this->chats->createForUser($user);
    }

    public function ensureForGroup(Group $group): Chat
    {
        $current = $group->getCurrentChat();
        if ($current !== null) {
            return $current;
        }

        return $this->chats->createForGroup($group);
    }
}

