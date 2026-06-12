<?php

declare(strict_types=1);

namespace App\Document;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Document;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Index;
use Doctrine\ODM\MongoDB\Mapping\Attribute\ReferenceOne;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;

#[Document(
    collection: 'payments',
    repositoryClass: PaymentRepository::class,
    indexes: [
        new Index(
            keys: ['telegramPaymentChargeId' => 1],
            unique: true,
            name: 'payment_telegram_charge_unique',
            partialFilterExpression: ['telegramPaymentChargeId' => ['$type' => 'string']],
        ),
        new Index(keys: ['invoicePayload' => 1], name: 'payment_invoice_payload'),
    ],
)]
final class Payment
{
    #[Id]
    private ?string $id = null;

    #[ReferenceOne(targetDocument: Balance::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private Balance $balance;

    #[ReferenceOne(targetDocument: User::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private User $payer;

    #[Field(type: 'int')]
    private int $amount;

    #[Field(type: 'string', enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::PENDING;

    #[Field(type: 'string', nullable: true)]
    private ?string $invoicePayload = null;

    #[Field(type: 'string', nullable: true)]
    private ?string $telegramPaymentChargeId = null;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Balance $balance, User $payer, int $amount)
    {
        $this->balance = $balance;
        $this->payer = $payer;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getBalance(): Balance
    {
        return $this->balance;
    }

    public function getPayer(): User
    {
        return $this->payer;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getInvoicePayload(): ?string
    {
        return $this->invoicePayload;
    }

    public function setInvoicePayload(string $invoicePayload): self
    {
        $this->invoicePayload = $invoicePayload;

        return $this;
    }

    public function getTelegramPaymentChargeId(): ?string
    {
        return $this->telegramPaymentChargeId;
    }

    public function setTelegramPaymentChargeId(string $telegramPaymentChargeId): self
    {
        $this->telegramPaymentChargeId = $telegramPaymentChargeId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function markCompleted(string $telegramPaymentChargeId): self
    {
        $this->status = PaymentStatus::COMPLETED;
        $this->telegramPaymentChargeId = $telegramPaymentChargeId;
        $this->paidAt = new \DateTimeImmutable();

        return $this;
    }
}
