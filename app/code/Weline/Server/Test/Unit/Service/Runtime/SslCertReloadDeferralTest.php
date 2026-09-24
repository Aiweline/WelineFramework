<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\SslCertReloadDeferral;

final class SslCertReloadDeferralTest extends TestCase
{
    public function testBusyOfferDefersAndCoalescesToLatestFence(): void
    {
        $gate = new SslCertReloadDeferral();
        self::assertTrue($gate->offerWhileBusy(['operation_id' => 'a' . \str_repeat('0', 31)], true));
        self::assertTrue($gate->offerWhileBusy(['operation_id' => 'b' . \str_repeat('0', 31)], true));
        self::assertNull($gate->takeWhenIdle(true));
        $taken = $gate->takeWhenIdle(false);
        self::assertIsArray($taken);
        self::assertSame('b' . \str_repeat('0', 31), $taken['operation_id']);
        self::assertFalse($gate->hasPending());
    }

    public function testIdleOfferDoesNotDefer(): void
    {
        $gate = new SslCertReloadDeferral();
        self::assertFalse($gate->offerWhileBusy(['operation_id' => 'c' . \str_repeat('0', 31)], false));
        self::assertFalse($gate->hasPending());
    }
}
