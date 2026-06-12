<?php

declare(strict_types=1);

namespace App\Message\Telegram;

/** pre_checkout_query перед оплатою Telegram Stars */
final readonly class ProcessTelegramPreCheckout
{
    public function __construct(public array $preCheckoutQuery) {}
}
