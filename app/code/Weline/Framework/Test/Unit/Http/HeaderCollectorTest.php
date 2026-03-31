<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;

final class HeaderCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
    }

    public function testStatusCodeIsOnlyMarkedExplicitAfterOverride(): void
    {
        $collector = HeaderCollector::getInstance();

        self::assertSame(200, $collector->getStatusCode());
        self::assertFalse($collector->hasExplicitStatusCode());

        $collector->setStatusCode(200);

        self::assertSame(200, $collector->getStatusCode());
        self::assertTrue($collector->hasExplicitStatusCode());
    }

    public function testResetClearsExplicitStatusOverrideFlag(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setStatusCode(401);

        HeaderCollector::reset();

        $resetCollector = HeaderCollector::getInstance();
        self::assertSame(200, $resetCollector->getStatusCode());
        self::assertFalse($resetCollector->hasExplicitStatusCode());
    }
}
