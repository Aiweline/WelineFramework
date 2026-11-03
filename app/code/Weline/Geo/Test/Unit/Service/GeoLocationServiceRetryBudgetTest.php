<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Exception;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Geo\Service\GeoLocationService;
use Weline\Geo\Service\Provider\GeoProviderInterface;

/**
 * 前台 /geo/location 会走到重试退避：退避总预算必须有界，且不允许在 Worker 内硬阻塞。
 */
final class GeoLocationServiceRetryBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        SchedulerSystem::setWaitDispatcher(null);
        SchedulerSystem::disableScheduler();
    }

    public function testRetryBackoffIsBoundedOnRequestPath(): void
    {
        $provider = $this->failingProvider();
        $service = $this->serviceWith([$provider], ['retry' => 5]);

        $startedAt = \microtime(true);
        try {
            $service->getLocationByIp('203.0.113.9');
            self::fail('所有提供者失败时必须抛出异常');
        } catch (Exception) {
            // expected
        }
        $elapsedMs = (\microtime(true) - $startedAt) * 1000;

        self::assertSame(6, $provider->calls, '重试语义不变：每个提供者尝试 retry+1 次');
        self::assertLessThan(400.0, $elapsedMs, '退避总预算必须有界（当前实现 5×100ms = 500ms）');
    }

    public function testRetryBackoffYieldsFiberWhenSchedulerActive(): void
    {
        SchedulerSystem::enableScheduler();
        $waits = [];
        SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use (&$waits): void {
            $waits[] = [$type, $params];
        });

        $service = $this->serviceWith([$this->failingProvider()], ['retry' => 3]);
        $fiber = new \Fiber(static function () use ($service): void {
            try {
                $service->getLocationByIp('203.0.113.9');
            } catch (Exception) {
                // expected
            }
        });

        $startedAt = \microtime(true);
        $fiber->start();
        $elapsedMs = (\microtime(true) - $startedAt) * 1000;

        self::assertTrue($fiber->isSuspended(), '调度器在线时退避必须挂起 Fiber，而不是阻塞 Worker');
        self::assertNotEmpty($waits, '退避必须通过调度器派发等待');
        self::assertSame('usleep', $waits[0][0]);
        self::assertLessThan(20.0, $elapsedMs, '挂起后瞬时返回，不得硬等 100ms');
    }

    /**
     * @param list<GeoProviderInterface> $providers
     * @param array<string, mixed> $config
     */
    private function serviceWith(array $providers, array $config): GeoLocationService
    {
        $reflection = new \ReflectionClass(GeoLocationService::class);
        /** @var GeoLocationService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('providers')->setValue($service, $providers);
        $reflection->getProperty('config')->setValue($service, $config);

        return $service;
    }

    private function failingProvider(): GeoProviderInterface
    {
        return new class implements GeoProviderInterface {
            public int $calls = 0;

            public function getLocationByIp(?string $ip = null): array
            {
                ++$this->calls;
                throw new \RuntimeException('provider unavailable');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'stub';
            }

            public function getPriority(): int
            {
                return 1;
            }
        };
    }
}
