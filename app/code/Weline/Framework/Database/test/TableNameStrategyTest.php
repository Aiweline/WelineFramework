<?php

declare(strict_types=1);

namespace Weline\Framework\Database\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlIdentifierFormatter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlTableNameStrategy;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultIdentifierFormatter;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy;

class TableNameStrategyTest extends TestCase
{
    public function testDefaultStrategyKeepsPrefixOnce(): void
    {
        $formatter = new DefaultIdentifierFormatter();
        $strategy = new DefaultTableNameStrategy($formatter, 'wl_');

        $this->assertSame('`core`.`wl_demo_table`', $strategy->resolve('demo_table', 'core'));
        $this->assertSame('`core`.`wl_demo_table`', $strategy->resolve('wl_demo_table', 'core'));
    }

    public function testPgsqlStrategyUsesPublicSchema(): void
    {
        $formatter = new PgsqlIdentifierFormatter();
        $strategy = new PgsqlTableNameStrategy($formatter, 'wl_', 'public');

        $this->assertSame('"public"."wl_demo"', $strategy->resolve('demo'));
        $this->assertSame('"public"."wl_demo"', $strategy->resolve('analytics.demo'));
    }

    public function testPgsqlStrategyKeepsQuotedPrefixedTableOnce(): void
    {
        $formatter = new PgsqlIdentifierFormatter();
        $strategy = new PgsqlTableNameStrategy($formatter, 'm_', 'public');

        $this->assertSame('"public"."m_acl"', $strategy->resolve('"public"."m_acl"'));
    }
}

