<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorGatewayLeaseDeadlineTest extends TestCase
{
    public function testEveryOrchestratorLeaseOperationUsesTheBoundedFactory(): void
    {
        $sourcePath = (new \ReflectionClass(ServiceOrchestrator::class))->getFileName();
        self::assertIsString($sourcePath);
        $source = \file_get_contents($sourcePath);
        self::assertIsString($source);

        self::assertSame(
            1,
            \substr_count($source, 'new GatewayPortLeaseAllocator('),
            'Only the bounded factory may construct a host lease allocator in the Master.',
        );
        self::assertStringContainsString(
            'operationDeadlineMonotonic: $deadlineMonotonic',
            $source,
        );
    }

    public function testFactorySuppliesAShortAbsoluteDeadline(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $before = \hrtime(true) / 1_000_000_000;
        $allocator = (new \ReflectionMethod(
            ServiceOrchestrator::class,
            'boundedGatewayPortLeaseAllocator',
        ))->invoke($orchestrator);
        $after = \hrtime(true) / 1_000_000_000;

        self::assertInstanceOf(GatewayPortLeaseAllocator::class, $allocator);
        $deadline = (new \ReflectionProperty(
            GatewayPortLeaseAllocator::class,
            'operationDeadlineMonotonic',
        ))->getValue($allocator);
        self::assertIsFloat($deadline);
        self::assertGreaterThan($after, $deadline);
        self::assertLessThanOrEqual($before + 3.05, $deadline);
    }

    public function testFactoryPreservesTheOwningTransactionDeadline(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $deadline = (\hrtime(true) / 1_000_000_000) + 1.25;
        $allocator = (new \ReflectionMethod(
            ServiceOrchestrator::class,
            'boundedGatewayPortLeaseAllocator',
        ))->invoke($orchestrator, $deadline);

        self::assertSame(
            $deadline,
            (new \ReflectionProperty(
                GatewayPortLeaseAllocator::class,
                'operationDeadlineMonotonic',
            ))->getValue($allocator),
        );
    }
}
