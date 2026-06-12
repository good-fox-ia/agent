<?php

declare(strict_types=1);

namespace App\MessageHandler\Telegram;

use App\Message\Telegram\ProcessTelegramPreCheckout;
use App\Service\Telegram\Payment\StarsPaymentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessTelegramPreCheckoutHandler
{
    public function __construct(private readonly StarsPaymentService $starsPayment) {}

    public function __invoke(ProcessTelegramPreCheckout $job): void
    {
        $this->starsPayment->handlePreCheckout($job->preCheckoutQuery);
    }
}
