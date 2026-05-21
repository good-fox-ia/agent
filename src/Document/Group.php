<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\GroupRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Document;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Index;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceMany;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceOne;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;

#[Document(
    collection: 'groups',
    repositoryClass: GroupRepository::class,
    indexes: [
        new Index(keys: ['telegramChatId' => 1], unique: true, name: 'telegram_group_chat_id_unique'),
    ],
)]
final class Group
{
    #[Id]
    private ?string $id = null;

    #[Field(type: 'int')]
    private int $telegramChatId;

    #[Field(type: 'string', nullable: true)]
    private ?string $type = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $title = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $username = null;

    /** @var Collection<int, User> */
    #[ReferenceMany(targetDocument: User::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private Collection $users;

    #[ReferenceOne(targetDocument: Chat::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private ?Chat $currentChat = null;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $telegramChatId)
    {
        $this->telegramChatId = $telegramChatId;
        $this->users = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTelegramChatId(): int
    {
        return $this->telegramChatId;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

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

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $this->touchUpdatedAt();
        }

        return $this;
    }

    public function getCurrentChat(): ?Chat
    {
        return $this->currentChat;
    }

    public function setCurrentChat(?Chat $currentChat): self
    {
        $this->currentChat = $currentChat;

        return $this->touchUpdatedAt();
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

    public function applyFromTelegramPayload(array $chat): self
    {
        if (isset($chat['type'])) $this->type = (string) $chat['type'];
        if (isset($chat['title'])) $this->title = (string) $chat['title'];
        if (isset($chat['username'])) $this->username = (string) $chat['username'];

        return $this->touchUpdatedAt();
    }
}
