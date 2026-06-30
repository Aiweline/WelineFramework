<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FullPageCacheCoordinator;

final class FullPageCacheDynamicBypassTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    public function testDynamicWarmupAndBenchmarkFlagsBypassFpc(): void
    {
        $coordinator = new FullPageCacheCoordinator();

        $_SERVER['WLS_INTERNAL_DYNAMIC_WARMUP'] = '1';
        self::assertTrue($coordinator->shouldBypassForDynamicFirstRender());

        $_SERVER = ['HTTP_X_WLS_DYNAMIC_BENCHMARK' => '1'];
        self::assertTrue($coordinator->shouldBypassForDynamicFirstRender());

        $_SERVER = ['HTTP_X_WLS_FPC_BYPASS' => '1'];
        self::assertTrue($coordinator->shouldBypassForDynamicFirstRender());
    }

    public function testRegularRequestDoesNotBypassFpc(): void
    {
        $coordinator = new FullPageCacheCoordinator();

        self::assertFalse($coordinator->shouldBypassForDynamicFirstRender());
    }
}
