<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\SearchRolloutGate;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

final class SearchRolloutGateTest extends TestCase
{
    public function testDefaultOffAndExactTupleAllowlist(): void
    {
        $gate = SearchRolloutGate::forTestingConfiguration();
        self::assertSame(
            CommerceRolloutGateInterface::MODE_OFF,
            $gate->mode(SearchRolloutGate::CAPABILITY),
        );
        self::assertFalse($gate->isEffectivelyOn(SearchRolloutGate::CAPABILITY, '0:1:1'));

        $gate->setMode(
            SearchRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['0:1:1', '7:8:9'],
        );
        self::assertTrue($gate->isEffectivelyOn(SearchRolloutGate::CAPABILITY, '0:1:1'));
        self::assertTrue($gate->isEffectivelyOn(SearchRolloutGate::CAPABILITY, '7:8:9'));
        self::assertFalse($gate->isEffectivelyOn(SearchRolloutGate::CAPABILITY, '0:1:2'));
        self::assertSame(
            ['0:1:1', '7:8:9'],
            \array_keys($gate->configuration()['allowlist']),
        );
    }

    public function testWebsiteOnlySubjectAndOnWithoutTokenAreRejected(): void
    {
        $gate = SearchRolloutGate::forTestingConfiguration();
        try {
            $gate->setMode(
                SearchRolloutGate::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                ['website:0'],
            );
            self::fail('website-only allowlist must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'search_rollout_subject_invalid',
                $exception->getMessage(),
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commerce_rollout_on_requires_explicit_token');
        $gate->setMode(
            SearchRolloutGate::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ON,
        );
    }

    public function testShadowIsObservationOnly(): void
    {
        $gate = SearchRolloutGate::forTestingConfiguration([
            'mode' => CommerceRolloutGateInterface::MODE_SHADOW,
            'allowlist' => [],
        ]);

        self::assertTrue($gate->isShadow(SearchRolloutGate::CAPABILITY));
        self::assertFalse($gate->isEffectivelyOn(SearchRolloutGate::CAPABILITY, '0:1:1'));
    }
}
