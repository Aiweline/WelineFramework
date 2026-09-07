<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontFpcWarmer;

final class StorefrontFpcWarmerTest extends TestCase
{
    public function testWarmerIdentity(): void
    {
        $warmer = new StorefrontFpcWarmer();
        self::assertSame('theme.storefront_fpc', $warmer->getName());
        self::assertSame('fpc', $warmer->getTargetPool());
        self::assertSame(800, $warmer->getPriority());
        self::assertTrue($warmer->canWarm());
    }

    public function testPriorityPathsForceLocales(): void
    {
        $warmer = new StorefrontFpcWarmer();
        $method = new \ReflectionMethod(StorefrontFpcWarmer::class, 'priorityPaths');
        $method->setAccessible(true);
        /** @var list<string> $paths */
        $paths = $method->invoke($warmer, ['/products/']);

        self::assertContains('/', $paths);
        self::assertContains('/en_US/', $paths);
        self::assertContains('/zh_Hans_CN/', $paths);
        self::assertContains('/en_US/products', $paths);
        self::assertContains('/zh_Hans_CN/products', $paths);
        self::assertContains('/products/', $paths);
        self::assertLessThanOrEqual(8, \count($paths));
    }

    public function testRequestHostIncludesNonStandardPortUsedByTheFpcKey(): void
    {
        $warmer = new StorefrontFpcWarmer();
        $method = new \ReflectionMethod(StorefrontFpcWarmer::class, 'requestHostWithPort');
        $method->setAccessible(true);

        self::assertSame('shop.test:9555', $method->invoke($warmer, 'shop.test', 9555));
        self::assertSame('shop.test', $method->invoke($warmer, 'shop.test', 443));
        self::assertSame('[::1]:9555', $method->invoke($warmer, '::1', 9555));
    }
}
