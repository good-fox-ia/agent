<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Chat;
use App\Document\Group;
use App\Document\User;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

final class ChatRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    public function createForUser(User $user): Chat
    {
        $chat = new Chat();
        $chat->setTitle(sprintf('Бесіда #%d', $user->getChats()->count() + 1));
        $chat->addUser($user);

        $user->addChat($chat);
        $user->setCurrentChat($chat);

        $this->persistAndFlush($chat, $user);

        return $chat;
    }

    public function findOneByIdForUser(string $id, User $user): ?Chat
    {
        if ($user->getId() === null) {
            return null;
        }

        $dm = $this->getDocumentManager();
        $managedUser = $dm->find(User::class, $user->getId());
        if ($managedUser === null) {
            return null;
        }

        foreach ($managedUser->getChats() as $chat) {
            if ($chat->getId() === $id) {
                return $chat;
            }
        }

        return null;
    }

    public function createForGroup(Group $group): Chat
    {
        $chat = new Chat();
        $chat->setTitle(sprintf('Група %s', $group->getTitle() ?? (string) $group->getTelegramChatId()));
        $chat->setGroup($group);
        $group->setCurrentChat($chat);

        $this->persistAndFlush($chat, $group);

        return $chat;
    }

    private function persistAndFlush(object ...$documents): void
    {
        $dm = $this->getDocumentManager();
        foreach ($documents as $document) {
            $dm->persist($document);
        }
        $dm->flush();
    }
}
