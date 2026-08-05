<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Service\VendorRolloutGate;

final class VendorRolloutGateTest extends TestCase
{
    public function testExactWebsiteStoreAllowlistAndModeOffCleanup(): void
    {
        $gate = VendorRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_OFF,
            'allowlist' => [],
        ]);
        $subject = VendorRolloutGate::scopeKey(0, 901);
        $gate->setMode(
            VendorRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            [$subject],
        );

        self::assertTrue($gate->isEffectivelyOn(VendorRolloutGate::CAPABILITY, $subject));
        self::assertFalse($gate->isEffectivelyOn(
            VendorRolloutGate::CAPABILITY,
            VendorRolloutGate::scopeKey(0, 902),
        ));
        self::assertSame(
            [['website_id' => 0, 'store_id' => 901]],
            $gate->configuration()['allowlist_rows'],
        );

        $gate->setMode(
            VendorRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );
        self::assertSame([], $gate->configuration()['allowlist_rows']);
        self::assertFalse($gate->isEffectivelyOn(VendorRolloutGate::CAPABILITY, $subject));
    }

    public function testBroadOrMalformedSubjectIsRejected(): void
    {
        $gate = VendorRolloutGate::forTestingConfiguration();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vendor_rollout_subject_invalid');
        $gate->setMode(
            VendorRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
    }
}
