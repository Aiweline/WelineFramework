<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect\SqliteIdentifierFormatter;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect\SqliteTableNameStrategy;

final class SqliteTableNameStrategyTest extends TestCase
{
    public function testLogicalDatabaseQualifierIsNotExposedToSqlite(): void
    {
        $strategy = new SqliteTableNameStrategy(new SqliteIdentifierFormatter());

        self::assertSame('"w_pixel"', $strategy->resolve('weline.w_pixel', 'weline'));
        self::assertSame('"w_pixel"', $strategy->resolve('`weline`.`w_pixel`', 'weline'));
        self::assertSame('"w_pixel"', $strategy->resolve('w_pixel', 'weline'));
    }

    public function testPhysicalPrefixIsAppliedExactlyOnce(): void
    {
        $strategy = new SqliteTableNameStrategy(new SqliteIdentifierFormatter(), 'app_');

        self::assertSame('"app_w_pixel"', $strategy->resolve('weline.w_pixel', 'weline'));
        self::assertSame('"app_w_pixel"', $strategy->resolve('app_w_pixel', 'weline'));
    }
}
