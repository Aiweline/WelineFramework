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
        self::assertContains('/products/', $paths);
        self::assertLessThanOrEqual(8, \count($paths));
    }
}
