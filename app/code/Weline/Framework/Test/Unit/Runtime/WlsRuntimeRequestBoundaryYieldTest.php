<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Router\Core as RouterCore;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeRequestBoundaryYieldTest extends TestCase
{
    public function testHandleDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            WlsRuntime::class,
            'handle',
            'A normal WLS request must not yield at framework phase boundaries before its response is returned.'
        );
    }

    public function testRouterDispatchDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            RouterCore::class,
            'start',
            'Router::start() is part of the normal HTTP response path and must not requeue the request fiber.'
        );
        $this->assertMethodDoesNotYield(
            RouterCore::class,
            'route',
            'Router::route() is part of the normal HTTP response path and must not requeue the request fiber.'
        );
    }

    public function testTemplateRenderingDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            PcController::class,
            'fetchTemplateWithEvents',
            'Template event rendering is part of normal HTML response assembly and must not requeue the request fiber.'
        );
    }

    private function assertMethodDoesNotYield(string $className, string $methodName, string $message): void
    {
        $method = new ReflectionMethod($className, $methodName);
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);
        self::assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString(
            'SchedulerSystem::yield()',
            $source,
            $message
        );
    }
}
