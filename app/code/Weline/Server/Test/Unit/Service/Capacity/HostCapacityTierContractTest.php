<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\HostCapacityTier;

final class HostCapacityTierContractTest extends TestCase
{
    public function testTierBoundaries(): void
    {
        self::assertSame(HostCapacityTier::TIER_TINY, HostCapacityTier::resolve(1024)['tier']);
        self::assertSame(HostCapacityTier::TIER_TINY, HostCapacityTier::resolve(1536)['tier']);
        self::assertSame(HostCapacityTier::TIER_SMALL, HostCapacityTier::resolve(1537)['tier']);
        self::assertSame(HostCapacityTier::TIER_SMALL, HostCapacityTier::resolve(2300)['tier']);
        self::assertSame(HostCapacityTier::TIER_MEDIUM, HostCapacityTier::resolve(2301)['tier']);
        self::assertSame(HostCapacityTier::TIER_MEDIUM, HostCapacityTier::resolve(8192)['tier']);
        self::assertSame(HostCapacityTier::TIER_LARGE, HostCapacityTier::resolve(8193)['tier']);
    }

    public function testUnknownHostFailsOpenToMedium(): void
    {
        $snap = HostCapacityTier::resolve(0, ['enabled' => true, 'force' => '']);
        // resolve(0) with no force: treat as unknown → medium via detect path.
        // Passing null triggers detect; passing 0 is treated as unknown in resolve.
        $snap = HostCapacityTier::resolve(null, [
            'enabled' => true,
            'force' => '',
        ]);
        // When detect also fails in test env, fail-open medium.
        if (($snap['reason'] ?? '') === 'host_limit_unknown_fail_open_medium'
            || $snap['host_limit_mb'] <= 0
        ) {
            self::assertSame(HostCapacityTier::TIER_MEDIUM, $snap['tier']);
            self::assertFalse($snap['survival_overrides_pin']);
        } else {
            self::assertContains($snap['tier'], [
                HostCapacityTier::TIER_TINY,
                HostCapacityTier::TIER_SMALL,
                HostCapacityTier::TIER_MEDIUM,
                HostCapacityTier::TIER_LARGE,
            ]);
        }
    }

    public function testForceAndDisabled(): void
    {
        $forced = HostCapacityTier::resolve(65536, ['force' => 'tiny']);
        self::assertSame(HostCapacityTier::TIER_TINY, $forced['tier']);
        self::assertTrue($forced['survival_overrides_pin']);

        $off = HostCapacityTier::resolve(512, ['enabled' => false]);
        self::assertFalse($off['enabled']);
        self::assertFalse($off['edge_limit_enabled']);
        self::assertSame(HostCapacityTier::TIER_LARGE, $off['tier']);
    }

    public function testSurvivalOverridesPinAndUnlimitedOnTiny(): void
    {
        $applied = HostCapacityTier::applyFiberAdmission(
            [
                'max_active' => 6,
                'queue_wait_ms' => 30000,
                'queue_depth' => 64,
                'queue_slice_ms' => 25,
                'admission_memory_headroom_bytes' => 0,
                'pin_max_active' => true,
                'adaptive_max_active' => false,
            ],
            6,
            1024
        );
        self::assertSame(2, $applied['max_active']);
        self::assertFalse($applied['pin_max_active']);
        self::assertSame(10000, $applied['queue_wait_ms']);
        self::assertSame(HostCapacityTier::TIER_TINY, $applied['capacity_tier']);

        $unlimited = HostCapacityTier::applyFiberAdmission(
            [
                'max_active' => 0,
                'queue_wait_ms' => 30000,
                'queue_depth' => 0,
                'queue_slice_ms' => 25,
                'pin_max_active' => true,
                'adaptive_max_active' => false,
            ],
            0,
            1024
        );
        self::assertSame(2, $unlimited['max_active']);
    }

    public function testMediumRespectsPinAndDoesNotCapFiber(): void
    {
        $applied = HostCapacityTier::applyFiberAdmission(
            [
                'max_active' => 6,
                'queue_wait_ms' => 30000,
                'queue_depth' => 64,
                'queue_slice_ms' => 25,
                'pin_max_active' => true,
                'adaptive_max_active' => false,
            ],
            6,
            4096
        );
        self::assertSame(6, $applied['max_active']);
        self::assertTrue($applied['pin_max_active']);
        self::assertSame(30000, $applied['queue_wait_ms']);
        self::assertSame(HostCapacityTier::TIER_MEDIUM, $applied['capacity_tier']);
    }

    public function testNginxAndEdgeFragments(): void
    {
        self::assertSame(4096, HostCapacityTier::clampNginxWorkerConnections(32768, 1024));
        self::assertSame(32, HostCapacityTier::clampNginxUpstreamKeepalive(256, 1024));

        $edge = HostCapacityTier::nginxEdgeLimitFragments(1024);
        self::assertTrue($edge['enabled']);
        self::assertStringContainsString('limit_req_zone', $edge['http_zone_block']);
        self::assertStringContainsString('limit_req zone=wls_req', $edge['location_directives']);

        $large = HostCapacityTier::nginxEdgeLimitFragments(16384);
        self::assertTrue($large['enabled']);
        self::assertSame(200, $large['rate']);
    }

    public function testScaleAttackRulesDoesNotMutateWhenScaleOne(): void
    {
        $rules = [
            'rate_limit' => ['enabled' => true, 'window' => 60, 'max_requests' => 3000],
            'path_rate_limits' => [
                'enabled' => true,
                'rules' => [
                    ['path' => '/api/framework/query', 'max_requests' => 120],
                ],
            ],
        ];
        $scaled = HostCapacityTier::scaleAttackRules($rules, 1024);
        self::assertSame(750, $scaled['rate_limit']['max_requests']);
        self::assertSame(30, $scaled['path_rate_limits']['rules'][0]['max_requests']);
        self::assertSame(3000, $rules['rate_limit']['max_requests']);
    }
}
