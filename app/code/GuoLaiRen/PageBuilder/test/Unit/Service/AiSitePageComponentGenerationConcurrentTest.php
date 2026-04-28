<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 并发护栏测试：
 * 1. 走 Fiber 路径时，每个组件任务必须能在 FiberTaskRunner 的 pump 上下文里执行；
 * 2. 总耗时显著低于串行 sum（证明 yieldDelay 在 pump 调度下真的让出 CPU/IO）。
 */
final class AiSitePageComponentGenerationConcurrentTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testGenerateComponentEventsConcurrentlyExposesPumpInsideEachTask(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $service = new class () extends AiSitePageComponentGenerationService {
            /** @var array<string, ?\Weline\Framework\Php\CurlStreamPump> */
            public array $observedPumps = [];

            public function __construct()
            {
                parent::__construct();
            }

            protected function isTestEnvironment(): bool
            {
                return false;
            }

            public function generateComponent(
                string $componentCode,
                string $name,
                string $region,
                string $prompt,
                array $defaultConfig,
                array $renderContext = []
            ): array {
                $this->observedPumps[$region] = FiberTaskRunner::currentPump();
                SchedulerSystem::yieldDelay(20);

                return [
                    'code' => $componentCode,
                    'name' => $name,
                    'region' => $region,
                    'phtml' => '<div>' . $region . '</div>',
                    'html' => '<div>' . $region . '</div>',
                    'default_config' => $defaultConfig,
                    'ai_data' => [],
                ];
            }
        };

        $isTestEnv = (function (): bool {
            return $this->isTestEnvironment();
        })->call($service);
        self::assertFalse($isTestEnv, 'Subclass must report isTestEnvironment()=false');

        $components = [];
        foreach (['alpha', 'beta', 'gamma', 'delta'] as $region) {
            $components[$region] = [
                'componentCode' => 'fake/' . $region,
                'name' => 'Fake ' . $region,
                'region' => $region,
                'prompt' => 'prompt-' . $region,
                'defaultConfig' => [],
                'renderContext' => [],
            ];
        }

        $events = [];
        foreach ($service->generateComponentEventsConcurrently($components) as $key => $event) {
            $events[$key] = $event;
        }

        self::assertCount(4, $events);
        foreach ($events as $event) {
            self::assertSame('fulfilled', $event['status']);
        }
        self::assertCount(4, $service->observedPumps);
        foreach ($service->observedPumps as $pump) {
            self::assertNotNull($pump, 'Each component task must see an active CurlStreamPump');
        }
        self::assertNull(FiberTaskRunner::currentPump(), 'Pump must be cleared after runEvents finishes');
    }

    public function testGenerateComponentEventsConcurrentlyOutperformsSerialSum(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $service = new class () extends AiSitePageComponentGenerationService {
            public function __construct()
            {
                parent::__construct();
            }

            protected function isTestEnvironment(): bool
            {
                return false;
            }

            public function generateComponent(
                string $componentCode,
                string $name,
                string $region,
                string $prompt,
                array $defaultConfig,
                array $renderContext = []
            ): array {
                SchedulerSystem::yieldDelay(50);
                return [
                    'code' => $componentCode,
                    'name' => $name,
                    'region' => $region,
                    'phtml' => '',
                    'html' => '',
                    'default_config' => $defaultConfig,
                    'ai_data' => [],
                ];
            }
        };

        $components = [];
        foreach (['a', 'b', 'c', 'd'] as $region) {
            $components[$region] = [
                'componentCode' => 'fake/' . $region,
                'name' => 'Fake ' . $region,
                'region' => $region,
                'prompt' => 'prompt-' . $region,
                'defaultConfig' => [],
                'renderContext' => [],
            ];
        }

        $start = \microtime(true);
        $count = 0;
        foreach ($service->generateComponentEventsConcurrently($components) as $event) {
            $count++;
            self::assertSame('fulfilled', $event['status']);
        }
        $elapsedMs = (\microtime(true) - $start) * 1000.0;

        self::assertSame(4, $count);
        // 串行 sum 为 4*50ms=200ms。并发应明显小于 180ms（留 10% 冗余）。
        self::assertLessThan(
            180.0,
            $elapsedMs,
            \sprintf('Concurrent runEvents took %.1fms (>=180ms), expected near max(50ms)', $elapsedMs)
        );
    }
}
