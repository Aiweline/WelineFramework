<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\SellingModePolicy;

final class SellingModePolicyTest extends TestCase
{
    public function testDefaultsEnableBothModesAndMoqStep(): void
    {
        $policy = SellingModePolicy::forTesting();

        self::assertTrue($policy->isModeEnabled('toc', 0));
        self::assertTrue($policy->isModeEnabled('tob', 0, 1));
        self::assertSame(5, $policy->defaultMoq());
        self::assertSame(5, $policy->defaultQtyStep());
        self::assertFalse($policy->isModeEnabled('unknown', 0));
    }

    public function testWebsiteAndStoreConfigOverrideWithFallback(): void
    {
        $policy = SellingModePolicy::forTesting([
            'website:0' => ['toc' => true, 'tob' => false],
            'store:0:2' => ['toc' => true, 'tob' => true],
        ]);

        self::assertFalse($policy->isModeEnabled('tob', 0, 1));
        self::assertTrue($policy->isModeEnabled('tob', 0, 2));
        self::assertTrue($policy->isModeEnabled('toc', 0, 1));
    }

    public function testProductFlagsFailSoftWhenUnset(): void
    {
        $policy = SellingModePolicy::forTesting();

        self::assertTrue($policy->isModeEnabled('tob', 0, 0, []));
        self::assertFalse($policy->isModeEnabled('tob', 0, 0, [
            SellingModePolicy::PRODUCT_FLAG_TOB => false,
        ]));
        self::assertTrue($policy->isModeEnabled('toc', 0, 0, [
            SellingModePolicy::PRODUCT_FLAG_TOB => false,
        ]));
        self::assertFalse($policy->isModeEnabled('toc', 0, 0, [
            SellingModePolicy::PRODUCT_FLAG_TOC => '0',
        ]));
    }

    public function testPreferredModeFromSessionFallsBackToToc(): void
    {
        $policy = SellingModePolicy::forTesting([
            'website:1' => ['toc' => true, 'tob' => false],
        ]);

        self::assertSame('toc', $policy->preferredModeFromSession('tob', 1));
        self::assertSame('toc', $policy->preferredModeFromSession('toc', 1));
        self::assertSame('toc', $policy->preferredModeFromSession(null, 1));

        $both = SellingModePolicy::forTesting([
            'website:1' => ['toc' => true, 'tob' => true],
        ]);
        self::assertSame('tob', $both->preferredModeFromSession('tob', 1));
    }
}
