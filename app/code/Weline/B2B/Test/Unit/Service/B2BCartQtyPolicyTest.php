<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BCartQtyPolicy;

final class B2BCartQtyPolicyTest extends TestCase
{
    public function testTocSkipsMoq(): void
    {
        $policy = new B2BCartQtyPolicy();
        $result = $policy->assertQty(['cart_type' => 'toc', 'qty' => 1]);
        self::assertTrue($result['ok']);
    }

    public function testTobBelowMoqFails(): void
    {
        $policy = new B2BCartQtyPolicy();
        $result = $policy->assertQty(['cart_type' => 'tob', 'qty' => 4]);
        self::assertFalse($result['ok']);
        self::assertSame(B2BCartQtyPolicy::ERROR_BELOW_MOQ, $result['error_code']);
    }

    public function testTobStepMismatchFails(): void
    {
        $policy = new B2BCartQtyPolicy();
        $result = $policy->assertQty(['cart_type' => 'tob', 'qty' => 6]);
        self::assertFalse($result['ok']);
        self::assertSame(B2BCartQtyPolicy::ERROR_STEP_MISMATCH, $result['error_code']);
    }

    public function testTobValidMultiplePasses(): void
    {
        $policy = new B2BCartQtyPolicy();
        $result = $policy->assertQty(['cart_type' => 'tob', 'qty' => 10]);
        self::assertTrue($result['ok']);
    }
}
