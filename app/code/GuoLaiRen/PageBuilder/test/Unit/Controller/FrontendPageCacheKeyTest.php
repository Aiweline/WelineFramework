<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Frontend\Page;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class FrontendPageCacheKeyTest extends TestCase
{
    public function testNormalizeHostCandidateCollapsesLoopbackAndPorts(): void
    {
        $controller = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Page::class, 'normalizeHostCandidate');
        $method->setAccessible(true);

        self::assertSame('', $method->invoke($controller, '127.0.0.1:9503'));
        self::assertSame('', $method->invoke($controller, 'https://127.0.0.1:9503/'));
        self::assertSame('', $method->invoke($controller, 'localhost:9503'));
        self::assertSame('', $method->invoke($controller, '[::1]:9503'));
        self::assertSame('p11005ce4.weline.test', $method->invoke($controller, 'p11005ce4.weline.test:9503'));
    }
}
