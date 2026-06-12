<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Payment;
use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;

final class PaymentRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findOneByInvoicePayload(string $invoicePayload): ?Payment
    {
        if ($invoicePayload === '') {
            return null;
        }

        return $this->findOneBy(['invoicePayload' => $invoicePayload]);
    }

    public function findOneByTelegramPaymentChargeId(string $telegramPaymentChargeId): ?Payment
    {
        if ($telegramPaymentChargeId === '') {
            return null;
        }

        return $this->findOneBy(['telegramPaymentChargeId' => $telegramPaymentChargeId]);
    }
}
