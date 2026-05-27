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
        self::assertStringContainsString('createCompletionGateRetryQueue($queue, $content, $message)', $source);
        self::assertStringContainsString('Queue #\' . $retryQueueId . \' marked retryable.', $source);
        self::assertStringNotContainsString("'queue', 'create'", $this->extractMethodSource($source, 'createCompletionGateRetryQueue'));
        self::assertStringContainsString('QUEUE_RETRY same_queue=', $source);
        self::assertStringContainsString('pagebuilder_queue_retry_scheduled', $source);
        self::assertStringContainsString("'missing_build_blueprint_tasks'", $source);
        self::assertStringContainsString("'failed_build_tasks'", $source);
        self::assertStringContainsString("reason === 'cancelled_build_tasks'", $source);
        self::assertStringNotContainsString('Build queue returned without any build task summary.', $source);
    }

    private function extractMethodSource(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = \strpos($source, $needle);
        self::assertIsInt($start);

        $brace = \strpos($source, '{', $start);
        self::assertIsInt($brace);
        $depth = 0;
        $length = \strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return \substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail('Unable to extract method source for ' . $method);
    }
}
