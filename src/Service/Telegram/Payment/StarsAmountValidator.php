<?php

declare(strict_types=1);

namespace App\Service\Telegram\Payment;

/**
 * Валідація суми поповнення Telegram Stars.
 */
final class StarsAmountValidator
{
    public const MIN_AMOUNT = 1;
    public const MAX_AMOUNT = 2500;

    /** @var list<int> */
    public const PRESET_AMOUNTS = [5, 10, 50, 100];

    private function __construct() {}

    public static function validate(int $amount): ?string
    {
        if ($amount < self::MIN_AMOUNT) {
            return sprintf('Мінімальна сума — %d ⭐.', self::MIN_AMOUNT);
        }

        if ($amount > self::MAX_AMOUNT) {
            return sprintf('Максимальна сума — %d ⭐.', self::MAX_AMOUNT);
        }

        return null;
    }

    public static function isPresetAmount(int $amount): bool
    {
        return in_array($amount, self::PRESET_AMOUNTS, true);
    }
}
