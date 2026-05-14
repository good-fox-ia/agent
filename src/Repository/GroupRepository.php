<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Group;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

final class GroupRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function upsertFromTelegramChatPayload(array $chatPayload): Group
    {
        $telegramChatId = (int) $chatPayload['id'];
        $group = $this->findOneBy(['telegramChatId' => $telegramChatId]) ?? new Group($telegramChatId);
        $group->applyFromTelegramPayload($chatPayload);

        $this->getDocumentManager()->persist($group);

        return $group;
    }
}
