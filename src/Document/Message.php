<?php

declare(strict_types=1);

namespace App\Document;

use App\Enum\MessageType;
use App\Repository\MessageRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Document;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Index;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceOne;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;

#[Document(
    collection: 'messages',
    repositoryClass: MessageRepository::class,
    indexes: [
        new Index(keys: ['telegramChatId' => 1, 'telegramMessageId' => 1], unique: true, name: 'telegram_chat_message_unique'),
    ],
)]
final class Message
{
    #[Id]
    private ?string $id = null;

    #[Field(type: 'int')]
    private int $telegramChatId;

    #[Field(type: 'int')]
    private int $telegramMessageId;

    #[Field(type: 'string', enumType: MessageType::class)]
    private MessageType $type;

    #[ReferenceOne(targetDocument: User::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private ?User $author = null;

    #[ReferenceOne(targetDocument: Group::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private ?Group $group = null;

    #[ReferenceOne(targetDocument: self::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private ?Message $replyTo = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $text = null;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $telegramChatId, int $telegramMessageId, MessageType $type)
    {
        $this->telegramChatId = $telegramChatId;
        $this->telegramMessageId = $telegramMessageId;
        $this->type = $type;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTelegramChatId(): int
    {
        return $this->telegramChatId;
    }

    public function getTelegramMessageId(): int
    {
        return $this->telegramMessageId;
    }

    public function getType(): MessageType
    {
        return $this->type;
    }

    public function setType(MessageType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getGroup(): ?Group
    {
        return $this->group;
    }

    public function setGroup(?Group $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getReplyTo(): ?Message
    {
        return $this->replyTo;
    }

    public function setReplyTo(?Message $replyTo): self
    {
        $this->replyTo = $replyTo;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = $text;

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
}
