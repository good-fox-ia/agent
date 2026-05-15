<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\User;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

final class UserRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @param array<string, mixed> $fromPayload поле from з Telegram message
     */
    public function upsertFromTelegramFromPayload(array $fromPayload): User
    {
        $telegramUserId = (int) $fromPayload['id'];
        $user = $this->findOneBy(['telegramUserId' => $telegramUserId]) ?? new User($telegramUserId);
        $user->applyFromTelegramPayload($fromPayload);

        $this->getDocumentManager()->persist($user);

        return $user;
    }

    public function findOneByUsername(string $username): ?User
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return null;
        }

        return $this->createQueryBuilder()
            ->field('username')->equals(new \MongoDB\BSON\Regex(
                '^'.preg_quote($username, '/').'$',
                'i',
            ))
            ->getQuery()
            ->getSingleResult();
    }
}
