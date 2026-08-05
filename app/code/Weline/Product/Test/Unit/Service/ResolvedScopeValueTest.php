<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\ResolvedScopeValue;
use Weline\Product\Service\CatalogConflictException;

final class ResolvedScopeValueTest extends TestCase
{
    public function testFactories(): void
    {
        $e = ResolvedScopeValue::explicit('v', 0, 'en_US');
        self::assertTrue($e->isExplicit());
        self::assertSame('v', $e->toArray()['value']);

        $c = ResolvedScopeValue::cleared(2, 'zh_Hans_CN');
        self::assertTrue($c->isCleared());
        self::assertSame('cleared_at_scope', $c->diagnostic);

        $u = ResolvedScopeValue::unresolved();
        self::assertTrue($u->isUnresolved());
    }

    public function testConflictExceptionCarriesCode(): void
    {
        $e = new CatalogConflictException('publish_version_conflict', 'x', ['a' => 1]);
        self::assertSame('publish_version_conflict', $e->errorCode());
        self::assertSame(['a' => 1], $e->context());
    }
}
