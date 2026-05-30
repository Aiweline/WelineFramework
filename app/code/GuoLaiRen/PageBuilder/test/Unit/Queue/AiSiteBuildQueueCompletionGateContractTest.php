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
        self::assertStringContainsString("private const REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED = 'pagebuilder.ai.inline_image_generation.disabled';", $source);
        self::assertStringContainsString('finalizeBuildTaskStatesAfterRunLoop($scope)', $source);
        self::assertStringContainsString('inspectBuildCompletionGate($scope)', $source);
        self::assertStringContainsString('createCompletionGateRetryQueue($queue, $content, $message)', $source);
        self::assertStringContainsString('queuedPayloadDisablesInlineImageGeneration($content, $scopePatch)', $source);
        self::assertStringContainsString('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES', $source);
        self::assertStringContainsString('RequestContext::remove(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED)', $source);
        self::assertStringContainsString('Queue #\' . $retryQueueId . \' marked retryable.', $source);
        self::assertStringNotContainsString("'queue', 'create'", $this->extractMethodSource($source, 'createCompletionGateRetryQueue'));
        self::assertStringContainsString('QUEUE_RETRY same_queue=', $source);
        self::assertStringContainsString('pagebuilder_queue_retry_scheduled', $source);
        self::assertStringContainsString('summarizeThrowableForQueueSurface($throwable)', $source);
        self::assertStringContainsString('logInternalBuildQueueDiagnostic($queueId, $operation, $publicId, $diagnostic, $surfaceMessage)', $source);
        self::assertStringContainsString('[AI Site Build Queue Diagnostic]', $source);
        self::assertStringContainsString('$throwable = new \RuntimeException($surfaceMessage, 0, $throwable);', $source);
        self::assertStringContainsString('ERROR_DIAGNOSTIC', $source);
        self::assertStringContainsString("'missing_build_plan_blocks'", $source);
        self::assertStringContainsString("'failed_build_plan_blocks'", $source);
        self::assertStringContainsString("reason === 'cancelled_build_plan_blocks'", $source);
        self::assertStringNotContainsString('Build queue returned without any build task summary.', $source);

        $passedGateSource = $this->extractMethodSource($source, 'markQueueBuildOperationPassedGate');
        self::assertStringContainsString("'last_gate_reason' => \$fullBuildGatePassed ? ''", $passedGateSource);
        self::assertStringContainsString("'completion_gate_snapshot' => \$this->stripGateSummary(\$gate)", $passedGateSource);
        self::assertStringContainsString('syncPageTypeLayoutsWithSharedComponents($scope)', $passedGateSource);
        self::assertStringContainsString("\$buildSummary['completion_gate'] = \$this->stripGateSummary(\$gate);", $passedGateSource);

        $markDoneSource = $this->extractMethodSource($source, 'markQueueDone');
        self::assertStringContainsString("'result' => \$line", $markDoneSource);
        self::assertStringNotContainsString("\$existing . PHP_EOL . \$line", $markDoneSource);

        $appendLifecycleSource = $this->extractMethodSource($source, 'appendQueueLifecycleLine');
        self::assertStringContainsString("'result' => \$line", $appendLifecycleSource);
        self::assertStringNotContainsString("\$existing . PHP_EOL . \$line", $appendLifecycleSource);
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
