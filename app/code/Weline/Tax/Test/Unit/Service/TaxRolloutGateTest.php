<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Tax\Service\TaxRolloutGate;

final class TaxRolloutGateTest extends TestCase
{
    public function testExactTupleAllowlistAndOffAreFailClosed(): void
    {
        $gate = TaxRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
            'shadow_sample_bp' => 2500,
        ]);
        self::assertTrue($gate->isShadow('tax'));
        self::assertFalse($gate->isEffectivelyOn('tax', '0:1:1'));
        self::assertSame(2500, $gate->shadowSampleBasisPoints());

        $gate->setMode('tax', CommerceRolloutGateInterface::MODE_ALLOWLIST, ['0:1:1']);
        self::assertTrue($gate->isEffectivelyOn('tax', '0:1:1'));
        self::assertFalse($gate->isEffectivelyOn('tax', '0:1:2'));
        self::assertFalse($gate->isEffectivelyOn('tax', 'website:0'));
        self::assertSame(
            [['website_id' => 0, 'store_id' => 1, 'channel_id' => 1]],
            array_values($gate->configuration()['allowlist_rows']),
        );

        $gate->setMode('tax', CommerceRolloutGateInterface::MODE_OFF);
        self::assertSame([], $gate->configuration()['allowlist']);
        self::assertFalse($gate->isEffectivelyOn('tax', '0:1:1'));
    }

    public function testMalformedOrDuplicateTupleIsRejected(): void
    {
        $gate = TaxRolloutGate::forTestingConfiguration();
        foreach (
            [
                ['website:0'],
                ['0:0:1'],
                ['0:1:0'],
                ['0:1:1', '0:1:1'],
            ] as $allowlist
        ) {
            try {
                $gate->setMode('tax', CommerceRolloutGateInterface::MODE_ALLOWLIST, $allowlist);
                self::fail('invalid allowlist was accepted: ' . json_encode($allowlist));
            } catch (\InvalidArgumentException) {
                self::assertSame(CommerceRolloutGateInterface::MODE_OFF, $gate->mode('tax'));
            }
        }
    }

    public function testOnRequiresExplicitAuthorizationToken(): void
    {
        $gate = TaxRolloutGate::forTestingConfiguration();
        try {
            $gate->setMode('tax', CommerceRolloutGateInterface::MODE_ON);
            self::fail('mode on did not require a token');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'commerce_rollout_on_requires_explicit_token',
                $exception->getMessage(),
            );
        }

        $gate->setMode('tax', CommerceRolloutGateInterface::MODE_ON, [], 'explicit-test-token');
        self::assertTrue($gate->isEffectivelyOn('tax', '0:1:999'));
    }
}
