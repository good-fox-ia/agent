<?php

declare(strict_types=1);

namespace App\Service\Telegram\Chat;

use App\Document\Chat;
use App\Document\User;

final class SharedChatHelper
{
    public function isSharedPrivateChat(Chat $chat): bool
    {
        if ($chat->getGroup() !== null) {
            return false;
        }

        return $this->countParticipants($chat) >= 2;
    }

    public function countParticipants(Chat $chat): int
    {
        return count($this->participants($chat));
    }

    /**
     * @return list<User>
     */
    public function participants(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->getUsers()->toArray(),
            static fn (mixed $u): bool => $u instanceof User,
        ));
    }

    /**
     * @return list<User>
     */
    public function participantsExcept(Chat $chat, User $exclude): array
    {
        $excludeId = $exclude->getId();

        return array_values(array_filter(
            $this->participants($chat),
            static fn (User $u): bool => $excludeId === null || $u->getId() !== $excludeId,
        ));
    }

    public function formatUserLabel(User $user): string
    {
        $username = $user->getUsername();
        if ($username !== null && trim($username) !== '') {
            return '@' . ltrim(trim($username), '@');
        }

        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'користувач';
    }

    public function formatUserDisplayName(User $user): string
    {
        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->formatUserLabel($user);
    }
}
