<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute\Document;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Index;


#[Document(
    collection: 'users',
    indexes: [
        new Index(keys: ['telegramUserId' => 1], unique: true, name: 'telegram_user_id_unique'),
    ],
)]
final class User
{
    #[Id]
    private ?string $id = null;

    #[Field(type: 'int')]
    private int $telegramUserId;

    #[Field(type: 'string', nullable: true)]
    private ?string $firstName = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $lastName = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $username = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $languageCode = null;

    #[Field(type: 'bool', nullable: true)]
    private ?bool $isPremium = null;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $telegramUserId)
    {
        $this->telegramUserId = $telegramUserId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTelegramUserId(): int
    {
        return $this->telegramUserId;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getLanguageCode(): ?string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(?string $languageCode): self
    {
        $this->languageCode = $languageCode;

        return $this;
    }

    public function getIsPremium(): ?bool
    {
        return $this->isPremium;
    }

    public function setIsPremium(?bool $isPremium): self
    {
        $this->isPremium = $isPremium;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function applyFromTelegramPayload(array $from): self
    {
        if (isset($from['first_name'])) $this->firstName = (string) $from['first_name'];
        if (isset($from['last_name'])) $this->lastName = (string) $from['last_name'];
        if (isset($from['username'])) $this->username = (string) $from['username'];
        if (isset($from['language_code'])) $this->languageCode = (string) $from['language_code'];
        if (isset($from['is_premium'])) $this->isPremium = (bool) $from['is_premium'];

        return $this->touchUpdatedAt();
    }
}
