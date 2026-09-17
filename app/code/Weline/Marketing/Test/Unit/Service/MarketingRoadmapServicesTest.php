<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Audience\AudienceSegment;
use Weline\Marketing\Service\AudienceSegmentMatcher;
use Weline\Marketing\Service\LifecycleWelcomeService;
use Weline\Marketing\Service\MarketingCartProgressService;
use Weline\Marketing\Service\WinbackDashboardAggregator;
use Weline\Marketing\Service\WinbackStepResolver;

final class MarketingRoadmapServicesTest extends TestCase
{
    public function testStepResolverWaitsForInterval(): void
    {
        $resolver = new WinbackStepResolver();
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $next = $resolver->resolveNext(
            1,
            'order:x',
            3,
            24,
            $now,
            static fn (int $c, string $u, int $s): bool => $s === 1,
            static fn (int $c, string $u, int $s): ?string => $s === 1 ? '2026-09-14 00:00:00' : null,
        );
        self::assertNotNull($next);
        self::assertSame(2, $next['step']);
        self::assertFalse($next['ready']);
    }

    public function testAudienceMatcherKinds(): void
    {
        $matcher = new AudienceSegmentMatcher(
            customerFacts: static fn (int $id): array => match ($id) {
                1 => ['created_at' => '2026-09-01 00:00:00', 'order_count' => 0, 'last_active_at' => '2026-09-01 00:00:00'],
                2 => ['created_at' => '2026-01-01 00:00:00', 'order_count' => 3, 'last_active_at' => '2026-01-01 00:00:00'],
                default => ['order_count' => 0],
            },
            loadSegment: static fn (int $id): ?array => match ($id) {
                10 => ['id' => 10, 'kind' => AudienceSegment::KIND_NEW_CUSTOMER, 'status' => 'enabled', 'config_json' => '{}'],
                11 => ['id' => 11, 'kind' => AudienceSegment::KIND_RETURNING, 'status' => 'enabled', 'config_json' => '{}'],
                12 => ['id' => 12, 'kind' => AudienceSegment::KIND_IDLE_DAYS, 'status' => 'enabled', 'config_json' => '{"idle_days":30}'],
                default => null,
            },
        );
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        self::assertTrue($matcher->matches(1, 10, $now));
        self::assertFalse($matcher->matches(2, 10, $now));
        self::assertTrue($matcher->matches(2, 11, $now));
        self::assertTrue($matcher->matches(2, 12, $now));
        self::assertFalse($matcher->matches(1, 12, $now));
    }

    public function testLifecycleWelcomeSendsOnceWithCoupon(): void
    {
        $logs = [];
        $mails = [];
        $svc = new LifecycleWelcomeService(
            loadCampaigns: static fn (): array => [[
                'id' => 1,
                'incentive_rule_id' => 9,
                'website_id' => 0,
                'segment_id' => 0,
            ]],
            hasSent: static fn (): bool => false,
            sendMail: static function (array $dto) use (&$mails): array {
                $mails[] = $dto;

                return ['success' => true, 'message' => ''];
            },
            issueCoupon: static fn (): array => ['coupon_code' => 'WELCOME9', 'coupon_id' => 1],
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
            matchesSegment: static fn (): bool => true,
        );
        $stats = $svc->handleRegistered([
            'customer_id' => 44,
            'email' => 'n@example.com',
            'customer_name' => 'Neo',
        ]);
        self::assertSame(1, $stats['sent']);
        self::assertSame('WELCOME9', $mails[0]['coupon_code'] ?? '');
        self::assertSame('sent', $logs[0]['status'] ?? '');
    }

    public function testCartProgressNearestUnmet(): void
    {
        $svc = new MarketingCartProgressService();
        $p = $svc->progress(
            ['subtotal' => 80, 'currency' => 'CNY'],
            [
                ['threshold' => 100, 'label' => '免邮'],
                ['threshold' => 200, 'label' => '满减'],
            ],
        );
        self::assertNotNull($p);
        self::assertSame(100.0, $p['threshold']);
        self::assertSame(20.0, $p['remaining']);
        self::assertFalse($p['met']);
        self::assertSame('CNY', $p['currency']);
    }

    public function testDashboardAggregatorPrefixes(): void
    {
        $agg = new WinbackDashboardAggregator();
        $summary = $agg->summarizeRows([
            ['status' => 'sent', 'order_uuid' => 'order:a', 'coupon_code' => 'A1', 'reason' => ''],
            ['status' => 'skipped', 'order_uuid' => 'qt:b', 'coupon_code' => '', 'reason' => 'unreachable'],
            ['status' => 'sent', 'order_uuid' => 'cart:c', 'coupon_code' => 'A1', 'reason' => ''],
            ['status' => 'failed', 'order_uuid' => 'legacy', 'coupon_code' => '', 'reason' => ''],
        ]);
        self::assertSame(4, $summary['total']);
        self::assertSame(2, $summary['by_status']['sent'] ?? 0);
        self::assertSame(1, $summary['by_reason']['unreachable'] ?? 0);
        self::assertSame(1, $summary['by_prefix']['order'] ?? 0);
        self::assertSame(1, $summary['by_prefix']['qt'] ?? 0);
        self::assertSame(1, $summary['by_prefix']['cart'] ?? 0);
        self::assertSame(1, $summary['by_prefix']['raw'] ?? 0);
        self::assertSame(2, $summary['coupon_sent']);
        self::assertSame(2, $summary['coupon_codes']['A1'] ?? 0);
    }
}
