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

    public function testOperationSseDoesNotRecreateMissingQueueRecord(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleOperationSse');

        self::assertStringNotContainsString('enqueueOperationQueueTask(', $methodSource);
        self::assertStringContainsString("'OPERATION_QUEUE_NOT_FOUND'", $methodSource);
        self::assertStringContainsString('operation_sse_missing_queue_record', $methodSource);
    }

    public function testWorkspaceSnapshotHandlerRemainsReadOnlyForWorkbenchStepStatus(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleWorkspaceSnapshot');

        self::assertStringNotContainsString('workbench_step_status', $methodSource);
        self::assertStringNotContainsString('mergeScope(', $methodSource);
        self::assertStringNotContainsString('replaceScope(', $methodSource);
        self::assertStringNotContainsString('appendWorkspaceEvent(', $methodSource);
        self::assertStringNotContainsString('autoResumeBuildQueueWhenTasksIncomplete(', $methodSource);
        self::assertStringContainsString('buildWorkspaceState($fresh, $adminId, 12, false)', $methodSource);
    }

    public function testWorkspaceGetDoesNotCreateSyncOrWriteLinkedScope(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'workspace');

        self::assertStringContainsString('buildWorkspaceFastViewState($session, $adminId)', $methodSource);
        self::assertStringContainsString('loadExistingLinkedWebsitesMirrorSessionFromScope(', $methodSource);
        self::assertStringNotContainsString('ensureLinkedWebsitesMirrorSession(', $methodSource);
        self::assertStringNotContainsString('syncPageBuilderScopeFromLinkedWebsitesSession(', $methodSource);
        self::assertStringNotContainsString('mergeScope(', $methodSource);
        self::assertStringNotContainsString('replaceScope(', $methodSource);
        self::assertStringNotContainsString('appendWorkspaceEvent(', $methodSource);
    }

    public function testWorkspacePollStateIsLightweightAndReadOnly(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'buildWorkspaceQueuePollState');

        foreach ([
            'mergeScope(',
            'replaceScope(',
            'appendWorkspaceEvent(',
            'persistRecoveredQueueOperationState(',
            'autoResumeBuildQueueWhenTasksIncomplete(',
            'buildWorkspaceState(',
            'buildWorkspaceFastViewState(',
            'listRecentEvents(',
            'buildVirtualPagesByType(',
            'syncFromBuildPlan(',
            'profileGenerationService->generate(',
        ] as $forbiddenCall) {
            self::assertStringNotContainsString($forbiddenCall, $methodSource);
        }

        self::assertStringContainsString('loadScopeFragment(', $methodSource);
        self::assertStringContainsString("'response_mode' => 'queue_poll'", $methodSource);
    }

    public function testWorkspaceFastViewDoesNotWriteOrAutoResume(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'buildWorkspaceFastViewState');

        self::assertStringContainsString('buildWorkspaceQueuePollState(', $methodSource);
        self::assertStringNotContainsString('mergeScope(', $methodSource);
        self::assertStringNotContainsString('replaceScope(', $methodSource);
        self::assertStringNotContainsString('appendWorkspaceEvent(', $methodSource);
        self::assertStringNotContainsString('autoResumeBuildQueueWhenTasksIncomplete(', $methodSource);
        self::assertStringNotContainsString('buildWorkspaceState(', $methodSource);
    }

    public function testAutoResumeBuildQueueIsPersistPathOnly(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'buildWorkspaceState');
        $persistGuardOffset = \strpos($methodSource, 'if ($persist) {');
        $autoResumeOffset = \strpos($methodSource, 'autoResumeBuildQueueWhenTasksIncomplete(');

        self::assertIsInt($persistGuardOffset);
        self::assertIsInt($autoResumeOffset);
        self::assertLessThan($autoResumeOffset, $persistGuardOffset);
    }

    private function extractMethodSource(string $source, string $methodName): string
    {
        $start = \strpos($source, 'function ' . $methodName . '(');
        self::assertIsInt($start, $methodName . ' missing');

        $next = false;
        foreach ([
            "\n    private function ",
            "\n    protected function ",
            "\n    public function ",
            "\n    private static function ",
            "\n    protected static function ",
            "\n    public static function ",
        ] as $pattern) {
            $candidate = \strpos($source, $pattern, $start + 1);
            if ($candidate !== false && ($next === false || $candidate < $next)) {
                $next = $candidate;
            }
        }

        return $next === false ? \substr($source, $start) : \substr($source, $start, $next - $start);
    }
}
