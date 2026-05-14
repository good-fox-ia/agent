<?php

declare(strict_types=1);

namespace App\Message\Telegram\Content;

/** Вхідне текстове (або caption) повідомлення Telegram — маршрутизується на черги private/group. */
final readonly class ProcessTelegramTextMessage
{
    public function __construct(public array $message) {}
}
