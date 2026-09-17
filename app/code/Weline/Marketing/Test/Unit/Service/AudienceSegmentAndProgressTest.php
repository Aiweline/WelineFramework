<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Audience\AudienceSegment;
use Weline\Marketing\Service\AudienceSegmentMatcher;
use Weline\Marketing\Service\MarketingCartProgressService;

final class AudienceSegmentAndProgressTest extends TestCase
{
    public function testNewCustomerMatchesWhenNoOrders(): void
    {
        $matcher = new AudienceSegmentMatcher(
            customerFacts: static fn (): array => ['order_count' => 0, 'created_at' => '2026-01-01 00:00:00'],
            loadSegment: static fn (): array => [
                'status' => AudienceSegment::STATUS_ENABLED,
                'kind' => AudienceSegment::KIND_NEW_CUSTOMER,
            ],
        );
        self::assertTrue($matcher->matches(1, 9));
    }

    public function testReturningRequiresPaidOrder(): void
    {
        $matcher = new AudienceSegmentMatcher(
            customerFacts: static fn (): array => ['order_count' => 2, 'created_at' => '2026-01-01 00:00:00'],
            loadSegment: static fn (): array => [
                'status' => AudienceSegment::STATUS_ENABLED,
                'kind' => AudienceSegment::KIND_RETURNING,
            ],
        );
        self::assertTrue($matcher->matches(1, 9));
        $matcher2 = new AudienceSegmentMatcher(
            customerFacts: static fn (): array => ['order_count' => 0],
            loadSegment: static fn (): array => [
                'status' => AudienceSegment::STATUS_ENABLED,
                'kind' => AudienceSegment::KIND_RETURNING,
            ],
        );
        self::assertFalse($matcher2->matches(1, 9));
    }

    public function testIdleDaysUsesConfig(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $matcher = new AudienceSegmentMatcher(
            customerFacts: static fn (): array => [
                'order_count' => 1,
                'last_active_at' => '2026-08-01 12:00:00',
            ],
            loadSegment: static fn (): array => [
                'status' => AudienceSegment::STATUS_ENABLED,
                'kind' => AudienceSegment::KIND_IDLE_DAYS,
                'config' => ['idle_days' => 30],
            ],
        );
        self::assertTrue($matcher->matches(1, 3, $now));
    }

    public function testProgressQualifiedAndRemaining(): void
    {
        $svc = new MarketingCartProgressService();
        $p = $svc->build(40.0, 100.0, 'CNY');
        self::assertTrue($p['enabled']);
        self::assertFalse($p['qualified']);
        self::assertSame(60.0, $p['remaining']);
        self::assertSame(40.0, $p['percent']);

        $q = $svc->build(120.0, 100.0, 'CNY');
        self::assertTrue($q['qualified']);
        self::assertSame(100.0, $q['percent']);
    }
}
