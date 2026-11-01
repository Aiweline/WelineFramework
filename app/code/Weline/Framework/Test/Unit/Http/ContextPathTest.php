<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;

/**
 * Context::path() 是解析器写终态（route.path）的唯一读取口。
 */
final class ContextPathTest extends TestCase
{
    private ?Context $contextBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextBackup = Context::getCurrent();
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->contextBackup !== null) {
            Context::enter($this->contextBackup);
        }
        parent::tearDown();
    }

    public function testReturnsRoutePath(): void
    {
        Context::enter(new Context(['route' => ['path' => '/a/b']]));

        self::assertSame('/a/b', Context::current()->path());
    }

    public function testFallsBackToRootWhenRoutePathEmpty(): void
    {
        Context::enter(new Context(['route' => ['path' => '']]));

        self::assertSame('/', Context::current()->path());
    }
}
