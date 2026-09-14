<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\PostResponseTaskQueue;

final class PostResponseTaskQueueDiagnosticsTest extends TestCase
{
    private array $originalTasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $property = new \ReflectionProperty(PostResponseTaskQueue::class, 'tasks');
        $this->originalTasks = $property->getValue();
        $property->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'tasks'))->setValue(null, $this->originalTasks);
        parent::tearDown();
    }

    public function testDiagnosticsClassifiesDueTasksWithoutConsumingOrExposingThem(): void
    {
        self::assertTrue(method_exists(PostResponseTaskQueue::class, 'getRuntimeDiagnostics'), '队列需要公开只读到期诊断。');
        self::assertSame([
            'pending' => 0, 'ready' => 0, 'delayed' => 0,
            'next_due_ms' => null, 'draining' => false,
        ], PostResponseTaskQueue::getRuntimeDiagnostics());

        $executed = [];
        $insideDrain = null;
        PostResponseTaskQueue::enqueue('private-task-a', static function () use (&$executed, &$insideDrain): void {
            $executed[] = 'a';
            $insideDrain = PostResponseTaskQueue::getRuntimeDiagnostics();
        });
        PostResponseTaskQueue::enqueue('private-task-b', static function () use (&$executed): void {
            $executed[] = 'b';
        }, microtime(true) - 1);
        PostResponseTaskQueue::enqueue('private-task-c', static function () use (&$executed): void {
            $executed[] = 'c';
        }, microtime(true) + 60);
        PostResponseTaskQueue::enqueue('private-task-d', static function (): void {}, microtime(true) + 120);

        $expected = ['pending' => 4, 'ready' => 2, 'delayed' => 2, 'next_due_ms' => 0.0, 'draining' => false];
        self::assertSame($expected, PostResponseTaskQueue::getRuntimeDiagnostics());
        self::assertSame($expected, PostResponseTaskQueue::getRuntimeDiagnostics());
        self::assertSame([], $executed);
        self::assertSame(4, PostResponseTaskQueue::pendingCount());
        self::assertSame(2, PostResponseTaskQueue::drain(100.0, 2));
        self::assertSame(['a', 'b'], $executed);
        self::assertSame(['pending' => 3, 'ready' => 1, 'delayed' => 2, 'next_due_ms' => 0.0, 'draining' => true], $insideDrain);

        $delayed = PostResponseTaskQueue::getRuntimeDiagnostics();
        self::assertSame(2, $delayed['pending']);
        self::assertSame(0, $delayed['ready']);
        self::assertSame(2, $delayed['delayed']);
        self::assertGreaterThan(59000.0, $delayed['next_due_ms']);
        self::assertLessThanOrEqual(60000.0, $delayed['next_due_ms']);
        self::assertFalse($delayed['draining']);
        self::assertSame(['pending', 'ready', 'delayed', 'next_due_ms', 'draining'], array_keys($delayed));
    }
}
