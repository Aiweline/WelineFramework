<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiSiteAgentPublishContractTest extends TestCase
{
    public function testPublishStartFailuresExposeStableMachineReadableCodes(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleStartPublish');

        foreach ([
            "'code' => 'LATEST_AI_BUILD_FAILED'",
            "'code' => 'PLAN_NOT_CONFIRMED'",
            "'code' => 'BUILD_PLAN_NOT_CONFIRMED'",
            "'code' => 'WORKSPACE_NOT_READY'",
            "'code' => 'PUBLISH_STAGE2_TASK_BLOCK_MISMATCH'",
            "'code' => 'PUBLISH_QUALITY_GATE_FAILED'",
            "'code' => 'VISUAL_THEME_CONFIRM_REQUIRED'",
        ] as $expectedCode) {
            self::assertStringContainsString($expectedCode, $methodSource);
        }
    }

    public function testPublishChecklistPayloadExposesStableResultAndItemCodes(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handlePublishChecklist');

        foreach ([
            "'code' => 'PLAN_NOT_CONFIRMED'",
            "'code' => 'BUILD_PLAN_NOT_CONFIRMED'",
            "'code' => 'LATEST_AI_BUILD_FAILED'",
            "'code' => 'DRAFT_WEBSITE_READY'",
            "'code' => 'VIRTUAL_THEME_READY'",
            "'code' => 'WEBSITE_PROFILE_READY'",
            "'code' => 'VIRTUAL_PAGES_READY'",
            "'code' => 'VISUAL_EDITOR_READY'",
            "'code' => 'STAGE2_TASK_BLOCK_INTEGRITY'",
            "'code' => \$passed ? 'PUBLISH_CHECKLIST_PASSED' : 'PUBLISH_CHECKLIST_BLOCKED'",
        ] as $expectedCode) {
            self::assertStringContainsString($expectedCode, $methodSource);
        }
        self::assertStringNotContainsString("'code' => 'HTML_BLOCKS_READY'", $methodSource);
        self::assertStringNotContainsString("'code' => 'SITE_READY'", $methodSource);
    }

    public function testPublishOperationRunsStageTwoTaskBlockGateBeforePublishService(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'runPublishOperation');

        $readinessPos = \strpos($methodSource, 'buildStageTwoPublishReadinessReport($scope)');
        $publishPos = \strpos($methodSource, '$this->publishService->publish(');
        self::assertIsInt($readinessPos);
        self::assertIsInt($publishPos);
        self::assertLessThan($publishPos, $readinessPos);
        self::assertStringContainsString("\$scope['stage2_publish_readiness'] = \$stageTwoReadiness;", $methodSource);
    }

    public function testPublishOperationIsQueueBacked(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');

        self::assertStringContainsString("'publish' => \\GuoLaiRen\\PageBuilder\\Queue\\AiSitePublishQueue::class", $source);
        self::assertStringContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'shouldEnqueueOperation'));
        self::assertStringContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'isAiSiteQueueBackedOperation'));
        self::assertStringContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'supportsBackgroundOperation'));
    }

    public function testOperationSseRecreatesMissingQueueRecordInsteadOfDeadEnding(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleOperationSse');

        self::assertStringContainsString('$newQueueId = $this->enqueueOperationQueueTask($session, $adminId, $operation, $executionToken);', $methodSource);
        self::assertStringNotContainsString('Queue record not found. Start the operation again so the controller can create one queue row.', $methodSource);
    }

    public function testWorkspaceSnapshotHandlerRemainsReadOnlyForWorkbenchStepStatus(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleWorkspaceSnapshot');

        self::assertStringNotContainsString('workbench_step_status', $methodSource);
        self::assertStringNotContainsString('mergeScope(', $methodSource);
        self::assertStringNotContainsString('replaceScope(', $methodSource);
        self::assertStringContainsString('buildWorkspaceState($fresh, $adminId, 12, false)', $methodSource);
    }

    private function extractMethodSource(string $source, string $methodName): string
    {
        $start = \strpos($source, 'function ' . $methodName . '(');
        self::assertIsInt($start, $methodName . ' missing');

        $next = \strpos($source, "\n    private function ", $start + 1);
        if ($next === false) {
            $next = \strpos($source, "\n    public function ", $start + 1);
        }

        return $next === false ? \substr($source, $start) : \substr($source, $start, $next - $start);
    }
}
