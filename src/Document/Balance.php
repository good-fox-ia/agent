<?php

declare(strict_types=1);

namespace App\Document;

use App\Repository\BalanceRepository;
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
    collection: 'balances',
    repositoryClass: BalanceRepository::class,
    indexes: [
        new Index(keys: ['user' => 1], unique: true, name: 'balance_user_unique'),
    ],
)]
final class Balance
{
    #[Id]
    private ?string $id = null;

    #[ReferenceOne(targetDocument: User::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private User $user;

    #[Field(type: 'int')]
    private int $amount = 0;

    /** @var Collection<int, Payment> */
    #[ReferenceMany(targetDocument: Payment::class, storeAs: ClassMetadata::REFERENCE_STORE_AS_ID)]
    private Collection $payments;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->payments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = max(0, $amount);
        $this->touchUpdatedAt();

        return $this;
    }

    public function credit(int $stars): self
    {
        if ($stars > 0) {
            $this->amount += $stars;
            $this->touchUpdatedAt();
        }

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $this->touchUpdatedAt();
        }

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
}
