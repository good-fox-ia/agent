<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Chat;
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

        $dm = $this->getDocumentManager();
        $dm->persist($chat);
        $dm->persist($user);
        $dm->flush();

        return $chat;
    }
}
