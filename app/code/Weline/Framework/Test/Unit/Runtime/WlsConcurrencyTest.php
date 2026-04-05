<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\WlsConcurrency;

final class WlsConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        parent::tearDown();
    }

    public function testCountZeroWhenNoProvider(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }

    public function testProviderValueIsReturnedAndClamped(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => 2);
        self::assertSame(2, WlsConcurrency::getOtherSuspendedRequestFiberCount());

        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => -1);
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }

    public function testProviderThrowableYieldsZero(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static function (): int {
            throw new \RuntimeException('fail');
        });
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }
}
