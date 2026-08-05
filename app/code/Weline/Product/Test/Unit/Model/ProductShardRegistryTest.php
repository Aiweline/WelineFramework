<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\ProductShardRegistry;

final class ProductShardRegistryTest extends TestCase
{
    public function testRejectsIllegalTerminalToTerminalTransitionBeforeDatabaseAccess(): void
    {
        $registry = (new \ReflectionClass(ProductShardRegistry::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $registry->compareAndSet(
            0,
            [ProductShardRegistry::STATUS_READY],
            ProductShardRegistry::STATUS_FAILED,
        );
    }

    public function testRejectsUnknownTransitionStateBeforeDatabaseAccess(): void
    {
        $registry = (new \ReflectionClass(ProductShardRegistry::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $registry->compareAndSet(0, ['unknown'], ProductShardRegistry::STATUS_PROVISIONING);
    }

    public function testRejectsReadyWithoutFingerprintBeforeDatabaseAccess(): void
    {
        $registry = (new \ReflectionClass(ProductShardRegistry::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $registry->markReady(0, '', '2.0.0');
    }
}
