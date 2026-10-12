<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Theme\Observer\WorkerBootstrapWarmup;

final class WorkerBootstrapWarmupRankContractTest extends TestCase
{
    public function testRankBucketsMatchIdleWarmupContract(): void
    {
        $ref = new ReflectionClass(WorkerBootstrapWarmup::class);
        $warmup = $ref->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WorkerBootstrapWarmup::class, 'fpcWarmupPathRank');
        $method->setAccessible(true);

        self::assertSame(0, $method->invoke($warmup, '/'));
        self::assertSame(0, $method->invoke($warmup, '/products'));
        self::assertSame(0, $method->invoke($warmup, '/en_US/'));
        self::assertSame(1, $method->invoke($warmup, '/categories'));
        self::assertSame(1, $method->invoke($warmup, '/category/kids'));
        self::assertSame(2, $method->invoke($warmup, '/product/demo-sku'));
        self::assertSame(3, $method->invoke($warmup, '/policy/privacy'));
        self::assertSame(4, $method->invoke($warmup, '/blog/hello'));
        self::assertSame(4, $method->invoke($warmup, '/product/view'));
    }
}
