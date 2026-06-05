<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

final class AiSitePlanJsonGenerationFanoutConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testStageOneFanoutRunnerUsesFiberPumpAndPreservesTaskKeys(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $service = new AiSitePlanJsonGenerationService();
        $method = new \ReflectionMethod($service, 'runCooperativeSessionTasksSettled');
        $method->setAccessible(true);

        $observedPumps = [];
        $tasks = [];
        foreach (['home_page', 'about_page', 'contact_page'] as $pageType) {
            $tasks[$pageType] = static function () use (&$observedPumps, $pageType): array {
                $observedPumps[$pageType] = FiberTaskRunner::currentPump();
                SchedulerSystem::yieldDelay(20);

                return [
                    'success' => true,
                    'page' => ['page_type' => $pageType],
                    'attempt_no' => 1,
                ];
            };
        }

        $results = $method->invoke($service, $tasks, ['concurrency' => 3]);

        self::assertSame(['home_page', 'about_page', 'contact_page'], \array_keys($results));
        foreach ($observedPumps as $pump) {
            self::assertNotNull($pump);
        }
        self::assertNull(FiberTaskRunner::currentPump());
        self::assertSame('about_page', $results['about_page']['page']['page_type'] ?? null);
    }

    public function testStageOneFanoutRunnerTurnsRejectedTaskIntoFailedResult(): void
    {
        $service = new AiSitePlanJsonGenerationService();
        $method = new \ReflectionMethod($service, 'runCooperativeSessionTasksSettled');
        $method->setAccessible(true);

        $results = $method->invoke($service, [
            'home_page' => static fn(): array => ['success' => true, 'page' => ['page_type' => 'home_page'], 'attempt_no' => 1],
            'bad_page' => static fn(): array => throw new \RuntimeException('broken page prompt'),
        ], ['concurrency' => 2]);

        self::assertTrue($results['home_page']['success'] ?? false);
        self::assertFalse($results['bad_page']['success'] ?? true);
        self::assertSame(2, $results['bad_page']['attempt_no'] ?? null);
        self::assertStringContainsString('broken page prompt', (string)($results['bad_page']['message'] ?? ''));
    }
}
