<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Subscription\Service\SubscriptionRolloutGate;
use Weline\Subscription\Service\SubscriptionService;

final class SubscriptionRolloutGateTest extends TestCase
{
    public function testExactWebsiteAllowlistAndModeOffAreFailClosed(): void
    {
        $gate = SubscriptionRolloutGate::forTestingConfiguration();
        self::assertSame(SubscriptionRolloutGate::MODE_OFF, $gate->mode(
            SubscriptionService::CAPABILITY,
        ));

        $gate->setMode(
            SubscriptionService::CAPABILITY,
            SubscriptionRolloutGate::MODE_ALLOWLIST,
            ['website:0'],
        );
        self::assertTrue($gate->isEffectivelyOn(
            SubscriptionService::CAPABILITY,
            'website:0',
        ));
        self::assertFalse($gate->isEffectivelyOn(
            SubscriptionService::CAPABILITY,
            'website:1',
        ));
        self::assertSame([['website_id' => 0]], $gate->configuration()['allowlist_rows']);

        $gate->setMode(
            SubscriptionService::CAPABILITY,
            SubscriptionRolloutGate::MODE_OFF,
        );
        self::assertSame([], $gate->configuration()['allowlist_rows']);
        $this->expectException(\RuntimeException::class);
        $gate->assertMutable(SubscriptionService::CAPABILITY, 'website:0');
    }

    public function testInvalidScopeAndUnauthorizedOnAreRejected(): void
    {
        $gate = SubscriptionRolloutGate::forTestingConfiguration();
        try {
            $gate->setMode(
                SubscriptionService::CAPABILITY,
                SubscriptionRolloutGate::MODE_ALLOWLIST,
                ['website:0:store:1'],
            );
            self::fail('expected invalid subject');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'subscription_rollout_subject_invalid',
                $exception->getMessage(),
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commerce_rollout_on_requires_explicit_token');
        $gate->setMode(
            SubscriptionService::CAPABILITY,
            SubscriptionRolloutGate::MODE_ON,
        );
    }
}
