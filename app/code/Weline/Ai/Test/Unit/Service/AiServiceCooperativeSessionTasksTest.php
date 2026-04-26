<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Framework\Runtime\SchedulerSystem;

final class AiServiceCooperativeSessionTasksTest extends TestCase
{
    private AiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        SchedulerSystem::disableScheduler();
        $this->service = (new \ReflectionClass(AiService::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();

        parent::tearDown();
    }

    public function testRunCooperativeSessionTasksUsesChildSessionsAndYields(): void
    {
        $events = [];

        $results = $this->service->runCooperativeSessionTasks([
            'slow' => static function (array $params, string|int $taskKey) use (&$events): array {
                $events[] = 'slow:start';
                self::assertSame('slow', $taskKey);
                SchedulerSystem::yieldDelay(10);
                $events[] = 'slow:end';

                return $params;
            },
            'fast' => static function (array $params, string|int $taskKey) use (&$events): array {
                $events[] = 'fast:start';
                self::assertSame('fast', $taskKey);
                $events[] = 'fast:end';

                return $params;
            },
        ], [
            'concurrency' => 2,
            'session_id' => 'pagebuilder-session',
            'params' => ['source' => 'pagebuilder'],
            'disable_conversation_history' => true,
            'disable_conversation_persist' => true,
        ]);

        self::assertSame(['slow:start', 'fast:start', 'fast:end', 'slow:end'], $events);
        self::assertSame(['slow', 'fast'], \array_keys($results));
        self::assertSame('pagebuilder', $results['slow']['source']);
        self::assertSame('pagebuilder', $results['fast']['source']);
        self::assertSame('pagebuilder-session', $results['slow']['cooperative_parent_session_id']);
        self::assertSame('pagebuilder-session', $results['fast']['cooperative_parent_session_id']);
        self::assertSame('slow', $results['slow']['cooperative_task_key']);
        self::assertSame('fast', $results['fast']['cooperative_task_key']);
        self::assertTrue($results['slow']['disable_conversation_history']);
        self::assertTrue($results['slow']['disable_conversation_persist']);
        self::assertStringStartsWith('pagebuilder-session.task.', $results['slow']['session_id']);
        self::assertStringStartsWith('pagebuilder-session.task.', $results['fast']['session_id']);
        self::assertNotSame($results['slow']['session_id'], $results['fast']['session_id']);
        self::assertFalse(SchedulerSystem::isSchedulerActive());
    }

    public function testSupportsCooperativeConcurrencyRejectsExistingScheduler(): void
    {
        self::assertTrue($this->service->supportsCooperativeConcurrency(2));

        SchedulerSystem::enableScheduler();

        self::assertFalse($this->service->supportsCooperativeConcurrency(2));
    }
}
