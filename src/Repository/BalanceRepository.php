<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Balance;
use App\Document\User;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

final class BalanceRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Balance::class);
    }

    public function findOneByUser(User $user): ?Balance
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getOrCreateForUser(User $user): Balance
    {
        $balance = $this->findOneByUser($user);
        if ($balance !== null) {
            return $balance;
        }

        $balance = new Balance($user);
        $this->getDocumentManager()->persist($balance);

        return $balance;
    }
}
