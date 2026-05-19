<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class QueueRunRespectStateTest extends TestCase
{
    public function testQueueRunRespectsPendingAndTerminalStateWrittenByQueueClass(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Console/Queue/Run.php');

        self::assertStringContainsString('$finalStatus === $queue::status_pending', $source);
        self::assertStringContainsString('$queue->setFinished(false)->save();', $source);
        self::assertStringContainsString('\\in_array($finalStatus, [$queue::status_stop, $queue::status_error], true)', $source);
    }
}
