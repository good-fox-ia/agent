<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram\Payment;

use App\Service\Telegram\Payment\StarsAmountValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StarsAmountValidatorTest extends TestCase
{
    #[DataProvider('provideValidAmounts')]
    public function testValidAmounts(int $amount): void
    {
        self::assertNull(StarsAmountValidator::validate($amount));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideValidAmounts(): iterable
    {
        yield 'мінімум' => [1];
        yield 'максимум' => [2500];
        yield 'пресет 5' => [5];
        yield 'пресет 100' => [100];
        yield 'довільна' => [25];
    }

    #[DataProvider('provideInvalidAmounts')]
    public function testInvalidAmounts(int $amount): void
    {
        self::assertNotNull(StarsAmountValidator::validate($amount));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidAmounts(): iterable
    {
        yield 'нуль' => [0];
        yield 'нижче мінімуму' => [-1];
        yield 'вище максимуму' => [2501];
    }

    public function testPresetAmounts(): void
    {
        foreach (StarsAmountValidator::PRESET_AMOUNTS as $amount) {
            self::assertTrue(StarsAmountValidator::isPresetAmount($amount));
        }

        self::assertFalse(StarsAmountValidator::isPresetAmount(25));
    }
}
