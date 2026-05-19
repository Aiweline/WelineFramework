<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Queue;

use PHPUnit\Framework\TestCase;

final class AiSiteBuildQueueCompletionGateContractTest extends TestCase
{
    public function testBuildQueueOwnsCompletionGateRetryState(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Queue/AiSiteBuildQueue.php');

        self::assertStringContainsString("private const CONTENT_ATTEMPT_KEY = 'attempt';", $source);
        self::assertStringContainsString("private const CONTENT_MAX_ATTEMPTS_KEY = 'max_attempts';", $source);
        self::assertStringContainsString("private const CONTENT_LAST_GATE_REASON_KEY = 'last_gate_reason';", $source);
        self::assertStringContainsString("private const CONTENT_LAST_GATE_SNAPSHOT_KEY = 'completion_gate_snapshot';", $source);
        self::assertStringContainsString('finalizeBuildTaskStatesAfterRunLoop($scope)', $source);
        self::assertStringContainsString('inspectBuildCompletionGate($scope)', $source);
        self::assertStringContainsString('requeueQueueToPending($queue, $content, $message)', $source);
        self::assertStringContainsString('pagebuilder_queue_retry_scheduled', $source);
    }
}
