<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\DateTime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Runtime\RequestContext;

final class TimezoneTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            RequestContext::resetWelineVars();
        } catch (\Throwable) {
        }
        while (Context::getCurrent() !== null) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testResolveWebsiteTimezonePrefersExplicitThenFallsBackToUtc(): void
    {
        self::assertSame('Asia/Shanghai', Timezone::resolveWebsiteTimezone('Asia/Shanghai'));
        self::assertSame('UTC', Timezone::resolveWebsiteTimezone('Not/AZone'));
    }

    public function testLocalInputToUtcSqlUsesWebsiteTimezone(): void
    {
        $utc = Timezone::localInputToUtcSql('2026-11-27T00:00', 'Asia/Shanghai');
        self::assertSame('2026-11-26 16:00:00', $utc);
    }

    public function testUtcSqlRoundTripToLocalInput(): void
    {
        $local = Timezone::utcSqlToLocalInput('2026-11-26 16:00:00', 'Asia/Shanghai');
        self::assertSame('2026-11-27T00:00', $local);
        $display = Timezone::utcSqlToLocalDisplay('2026-11-26 16:00:00', 'Asia/Shanghai');
        self::assertSame('2026-11-27 00:00:00', $display);
    }

    public function testIsWithinUtcWindowEmptyEndsAreOpen(): void
    {
        self::assertTrue(Timezone::isWithinUtcWindow(null, null, '2026-01-01 00:00:00'));
        self::assertTrue(Timezone::isWithinUtcWindow('2026-01-01 00:00:00', null, '2026-01-02 00:00:00'));
        self::assertFalse(Timezone::isWithinUtcWindow('2026-01-02 00:00:00', null, '2026-01-01 00:00:00'));
        self::assertFalse(Timezone::isWithinUtcWindow(null, '2026-01-01 00:00:00', '2026-01-02 00:00:00'));
    }

    public function testUtcNowSqlIsGmtFormat(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Timezone::utcNowSql());
    }

    public function testMigrateNaiveLocalToUtcSql(): void
    {
        self::assertSame(
            '2026-11-26 16:00:00',
            Timezone::migrateNaiveLocalToUtcSql('2026-11-27 00:00:00', 'Asia/Shanghai'),
        );
    }

    public function testResolveUsesRequestContextTimezone(): void
    {
        Context::enter(new Context([]));
        RequestContext::setWelineTimezone('America/New_York');
        self::assertSame('America/New_York', Timezone::resolveWebsiteTimezone());
        $utc = Timezone::localInputToUtcSql('2026-01-15T12:00');
        self::assertNotNull($utc);
        self::assertSame('2026-01-15T12:00', Timezone::utcSqlToLocalInput($utc));
    }
}
