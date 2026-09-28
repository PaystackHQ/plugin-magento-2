<?php

namespace Pstk\Paystack\Test\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use Pstk\Paystack\Gateway\SubunitConverter;

class SubunitConverterTest extends TestCase
{
    public function testWholeNumberConvertsCleanly(): void
    {
        $this->assertSame(500000, SubunitConverter::toSubunit(5000.00));
    }

    /**
     * @dataProvider inexactAmountProvider
     */
    public function testFractionalAmountsRoundToNearestSubunit(float $amount, int $expected): void
    {
        $this->assertSame($expected, SubunitConverter::toSubunit($amount));
    }

    public static function inexactAmountProvider(): array
    {
        return [
            '19.99 -> 1999' => [19.99, 1999],
            '8.21 -> 821'   => [8.21, 821],
            '1.10 -> 110'   => [1.10, 110],
            '0.29 -> 29'    => [0.29, 29],
        ];
    }

    public function testNumericStringDoesNotThrow(): void
    {
        $this->assertSame(1999, SubunitConverter::toSubunit('19.9900'));
    }

    public function testZeroConvertsToZero(): void
    {
        $this->assertSame(0, SubunitConverter::toSubunit(0));
    }

    public function testNegativeAmountConvertsToNegativeSubunit(): void
    {
        $this->assertSame(-1999, SubunitConverter::toSubunit(-19.99));
    }

    /**
     * Both current callers (Setup::processAuthorization and
     * TransactionValidator::validate) guard with is_numeric() before calling
     * this, so a non-numeric string is unreachable today. But this class's
     * own docblock says it exists so the two callers can never independently
     * drift — a future caller that drops the is_numeric guard must still get
     * a fail-closed 0, not a silent cast surprise or a thrown TypeError.
     */
    public function testNonNumericStringConvertsToZeroNotThrown(): void
    {
        $this->assertSame(0, SubunitConverter::toSubunit('abc'));
    }
}
