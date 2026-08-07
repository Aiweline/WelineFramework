<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Api\Runtime\RuntimeControlBroadcaster;
use Weline\Server\Api\Runtime\RuntimeNamespaceInvalidationPublisher;
use Weline\Server\Runtime\Async\AsyncBizAdapters;
use Weline\Server\Service\Runtime\NamespaceInvalidationOperationQueue;
use Weline\Server\Service\Runtime\WorkerNamespaceGenerationApplier;

final class NamespaceInvalidationMonotonicTimingTest extends TestCase
{
    /**
     * @dataProvider runtimeTimingClassProvider
     */
    public function testRuntimeDeadlinesAndRetentionDoNotUseWallClockMicrotime(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    public function testNamespaceStatusKeepsWallClockFieldsSeparateFromRetentionClock(): void
    {
        $source = (string)\file_get_contents(
            (new \ReflectionClass(NamespaceInvalidationOperationQueue::class))->getFileName(),
        );

        self::assertStringContainsString("'accepted_at' => self::wallClockSeconds()", $source);
        self::assertStringContainsString("'finished_monotonic' => \$finishedMonotonic", $source);
        self::assertStringContainsString("\$entry['finished_monotonic']", $source);
    }

    /** @return iterable<string,array{class-string}> */
    public static function runtimeTimingClassProvider(): iterable
    {
        yield 'cache clear broadcaster wait deadline' => [RuntimeControlBroadcaster::class];
        yield 'namespace publisher wait deadline' => [RuntimeNamespaceInvalidationPublisher::class];
        yield 'namespace operation retention' => [NamespaceInvalidationOperationQueue::class];
        yield 'worker idempotency retention' => [WorkerNamespaceGenerationApplier::class];
        yield 'async dispatch elapsed time' => [AsyncBizAdapters::class];
    }
}
