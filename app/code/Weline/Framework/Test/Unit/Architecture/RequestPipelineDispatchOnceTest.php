<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App;
use Weline\Framework\Runtime\WlsRuntime;

final class RequestPipelineDispatchOnceTest extends TestCase
{
    public function testFpmApplicationPipelineDispatchesRouterExactlyOnce(): void
    {
        $source = $this->methodSource(App::class, 'runPipeline');

        self::assertSame(1, substr_count($source, '$this->runRouter('));
        self::assertLessThan(
            strpos($source, '$this->runRouter('),
            strpos($source, '$this->startSessionIfNeeded('),
            'Session initialization must occur before the single router dispatch.',
        );
    }

    public function testWlsPipelineDispatchesRouterExactlyOnce(): void
    {
        $source = $this->methodSource(WlsRuntime::class, 'handle');

        self::assertSame(1, substr_count($source, '$app->runRouter('));
        self::assertStringNotContainsString('account.index.timing', $source);
        self::assertStringNotContainsString('category.view.profile', $source);
        self::assertStringNotContainsString('product.view.profile', $source);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
