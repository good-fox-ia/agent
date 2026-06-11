<?php

declare(strict_types=1);

namespace App\Document;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Document;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Index;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceMany;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceOne;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;

#[Document(
    collection: 'users',
    repositoryClass: UserRepository::class,
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

    #[Field(type: 'bool', nullable: true)]
    private ?bool $activeKeyboard = null;

    #[Field(type: 'bool', nullable: true)]
    private ?bool $voiceReply = null;

    /** @var Collection<int, User> */
    #[ReferenceMany(targetDocument: self::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private Collection $friends;

    /** @var Collection<int, Chat> */
    #[ReferenceMany(targetDocument: Chat::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private Collection $chats;

    #[ReferenceOne(targetDocument: Chat::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private ?Chat $currentChat = null;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $telegramUserId)
    {
        $this->telegramUserId = $telegramUserId;
        $this->friends = new ArrayCollection();
        $this->chats = new ArrayCollection();
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

    public function isActiveKeyboard(): bool
    {
        return $this->activeKeyboard ?? true;
    }

    public function setActiveKeyboard(bool $activeKeyboard): self
    {
        $this->activeKeyboard = $activeKeyboard;
        $this->touchUpdatedAt();

        return $this;
    }

    public function isVoiceReplyEnabled(): bool
    {
        return $this->voiceReply ?? false;
    }

    public function setVoiceReply(bool $voiceReply): self
    {
        $this->voiceReply = $voiceReply;
        $this->touchUpdatedAt();

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getFriends(): Collection
    {
        return $this->friends;
    }

    public function addFriend(User $friend): self
    {
        if ($friend !== $this && !$this->friends->contains($friend)) {
            $this->friends->add($friend);
            $this->touchUpdatedAt();
        }

        return $this;
    }

    public function removeFriend(User $friend): self
    {
        if ($this->friends->removeElement($friend)) {
            $this->touchUpdatedAt();
        }

        return $this;
    }

    /**
     * @return Collection<int, Chat>
     */
    public function getChats(): Collection
    {
        return $this->chats;
    }

    public function addChat(Chat $chat): self
    {
        if (!$this->chats->contains($chat)) {
            $this->chats->add($chat);
            $this->touchUpdatedAt();
        }

        return $this;
    }

    public function removeChat(Chat $chat): self
    {
        if ($this->chats->removeElement($chat)) {
            if ($this->currentChat === $chat) {
                $this->currentChat = null;
            }
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
        $this->touchUpdatedAt();

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
