<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

final class B2BRolloutGateTest extends TestCase
{
    public function testExactWebsiteAllowlistAndModeOffAreFailClosed(): void
    {
        $gate = B2BRolloutGate::forTestingConfiguration();
        self::assertSame(
            CommerceRolloutGateInterface::MODE_OFF,
            $gate->mode(B2BPriceEngine::CAPABILITY),
        );
        self::assertFalse($gate->isEffectivelyOn(B2BPriceEngine::CAPABILITY, 'website:0'));

        $gate->setMode(
            B2BPriceEngine::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        self::assertTrue($gate->isEffectivelyOn(B2BPriceEngine::CAPABILITY, 'website:0'));
        self::assertFalse($gate->isEffectivelyOn(B2BPriceEngine::CAPABILITY, 'website:1'));
        self::assertSame(
            [['website_id' => 0]],
            $gate->configuration()['allowlist_rows'],
        );

        $gate->setMode(B2BPriceEngine::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);
        self::assertFalse($gate->isEffectivelyOn(B2BPriceEngine::CAPABILITY, 'website:0'));
    }

    public function testInvalidScopeAndUnauthorizedOnAreRejected(): void
    {
        $gate = B2BRolloutGate::forTestingConfiguration();

        try {
            $gate->setMode(
                B2BPriceEngine::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                ['group:g-dealer'],
            );
            self::fail('expected exact Website scope rejection');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('b2b_rollout_subject_invalid', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commerce_rollout_on_requires_explicit_token');
        $gate->setMode(
            B2BPriceEngine::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ON,
        );
    }
}
