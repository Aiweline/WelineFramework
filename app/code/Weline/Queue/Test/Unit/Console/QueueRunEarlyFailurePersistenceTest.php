<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class QueueRunEarlyFailurePersistenceTest extends TestCase
{
    public function testConsumerBootstrapFailureIsPersistedBeforeWorkerExit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');
        $bootstrapFailure = \strpos($source, 'QUEUE_CONSUMER_BOOTSTRAP_FAILED:');
        $consumerResolution = \strpos($source, 'ObjectManager::getInstance($queueClass)');
        $executeTry = \strpos($source, '$this->executeQueueConsumer($queue_execute, $queue)');

        self::assertNotFalse($consumerResolution);
        self::assertNotFalse($bootstrapFailure);
        self::assertNotFalse($executeTry);
        self::assertStringContainsString('$consumerExecutionStarted = false;', $source);
        self::assertStringContainsString('$consumerExecutionStarted = true;', $source);
        self::assertLessThan(
            $executeTry,
            \strpos($source, '$consumerExecutionStarted = true;'),
            'Consumer failures must not be mislabeled as bootstrap failures.',
        );
        self::assertStringContainsString('$bootstrapFailure = !$consumerExecutionStarted;', $source);
        self::assertStringContainsString('failQueueWorkerSafely(', $source);
        self::assertStringContainsString(
            '$bootstrapFailure ? self::CONSUMER_BOOTSTRAP_FAILURE_PREFIX',
            $source,
        );
        self::assertStringNotContainsString('->save()', $source);
    }
}
