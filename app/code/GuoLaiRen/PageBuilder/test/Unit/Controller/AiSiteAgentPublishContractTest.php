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
            "'code' => 'PLAN_JSON_NOT_CONFIRMED'",
            "'code' => 'BUILD_COMPLETION_GATE_BLOCKED'",
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
            "'code' => 'PLAN_JSON_NOT_CONFIRMED'",
            "'code' => 'LATEST_AI_BUILD_FAILED'",
            "'code' => 'VIRTUAL_THEME_READY'",
            "'code' => 'WEBSITE_PROFILE_READY'",
            "'code' => 'VISUAL_EDITOR_READY'",
            "'code' => 'BUILD_COMPLETION_GATE'",
            "'code' => 'STAGE2_TASK_BLOCK_INTEGRITY'",
            "'code' => \$passed ? 'PUBLISH_CHECKLIST_PASSED' : 'PUBLISH_CHECKLIST_BLOCKED'",
        ] as $expectedCode) {
            self::assertStringContainsString($expectedCode, $methodSource);
        }
        self::assertStringNotContainsString("'code' => 'HTML_BLOCKS_READY'", $methodSource);
        self::assertStringNotContainsString("'code' => 'SITE_READY'", $methodSource);
    }

    public function testPublishEntrypointsUseFreshBuildCompletionGateInsteadOfCachedCanPublish(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $startSource = $this->extractMethodSource($source, 'handleStartPublish');
        $checklistSource = $this->extractMethodSource($source, 'handlePublishChecklist');

        foreach ([$startSource, $checklistSource] as $methodSource) {
            self::assertStringContainsString('inspectBuildCompletionGate($scope)', $methodSource);
            self::assertStringContainsString('$buildAlreadyComplete = $completionGatePassed;', $methodSource);
            self::assertStringNotContainsString("\$this->taskSummaryIndicatesCompleted(\$planJsonTaskSummary)\n            || !empty(\$scope['can_publish'])", $methodSource);
        }
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

    public function testStageTwoPublishReadinessCountsCanonicalPlanJsonBlocks(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $actualSource = $this->extractMethodSource($source, 'countActualStageTwoMaterializedBlocks');
        $pageSource = $this->extractMethodSource($source, 'countGeneratedContentBlocksForPage');
        $sharedSource = $this->extractMethodSource($source, 'countGeneratedSharedBlocks');

        self::assertStringContainsString('$virtualPages = $this->planJsonPages($scope);', $actualSource);
        self::assertStringContainsString('$this->planJsonDynamicBlocks($virtualPage)', $pageSource);
        self::assertStringContainsString("\$scope['plan_json']['shared_components'] ?? null", $sharedSource);
        self::assertStringContainsString("(int)(\$block['status'] ?? 0) === 1", $pageSource);
        self::assertStringContainsString("\$block['html'] ?? \$block['html_content'] ?? \$block['phtml'] ?? ''", $pageSource);
        self::assertStringNotContainsString('$pageTypeLayouts', $actualSource . $sharedSource);
        self::assertStringNotContainsString("\$virtualPage['blocks']", $pageSource);
    }

    public function testPublishOperationIsSynchronousAfterGates(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $startSource = $this->extractMethodSource($source, 'handleStartPublish');

        self::assertStringContainsString("\$this->runPublishOperation(\$this->silentSseWriter(), \$fresh, \$adminId)", $startSource);
        self::assertStringNotContainsString("\$this->startOperation(\n            \$session,\n            \$adminId,\n            'publish'", $startSource);
        self::assertStringNotContainsString("'publish' => \\GuoLaiRen\\PageBuilder\\Queue\\AiSitePublishQueue::class", $source);
        self::assertStringNotContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'shouldEnqueueOperation'));
        self::assertStringNotContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'isAiSiteQueueBackedOperation'));
        self::assertStringNotContainsString("'image_asset', 'publish'", $this->extractMethodSource($source, 'supportsBackgroundOperation'));
        self::assertStringContainsString("'plan', 'build'", $this->extractMethodSource($source, 'shouldEnqueueOperation'));
        self::assertStringContainsString("'plan', 'build'", $this->extractMethodSource($source, 'isAiSiteQueueBackedOperation'));
    }

    public function testOperationSseDoesNotRecreateMissingQueueRecord(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleOperationSse');

        self::assertStringNotContainsString('enqueueOperationQueueTask(', $methodSource);
        self::assertStringContainsString("'OPERATION_QUEUE_NOT_FOUND'", $methodSource);
        self::assertStringContainsString('operation_sse_missing_queue_record', $methodSource);
    }

    public function testWorkspaceStateHandlerRemainsReadOnlyForWorkbenchStepStatus(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'handleWorkspaceState');

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
            'autoResumeBuildQueueWhenTasksIncomplete(',
            'buildWorkspaceState(',
            'buildWorkspaceFastViewState(',
            'listRecentEvents(',
            'buildVirtualPagesByType(',
            'syncFromPlanJson(',
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

    public function testWorkspaceStateHydratesCanonicalPlanJsonArtifactForBlockTabs(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $workspaceState = $this->extractMethodSource($source, 'buildWorkspaceState');
        $artifactKeys = $this->extractConstArraySource($source, 'WORKSPACE_FAST_VIEW_ARTIFACT_KEYS_BY_STAGE');

        self::assertSame(3, \substr_count($artifactKeys, "'plan_json'"));
        self::assertSame(3, \substr_count($artifactKeys, "'plan_markdown'"));
        self::assertStringContainsString('$this->workspaceFastViewArtifactKeys(', $workspaceState);
        self::assertStringNotContainsString("normalizeStage(\$session->getStage()),\n                []", $workspaceState);
    }

    public function testWorkspaceInitialStatePrunesDesignDirectionSnapshotForInlineScript(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $workspaceSource = $this->extractMethodSource($source, 'workspace');
        $stateSource = $this->extractMethodSource($source, 'buildWorkspaceState');
        $pruneSource = $this->extractMethodSource($source, 'pruneWorkspaceDesignDirectionSnapshotForView');

        self::assertStringContainsString('pruneWorkspaceDesignDirectionStateForView($directionState)', $workspaceSource);
        self::assertStringContainsString('pruneWorkspaceDesignDirectionStateForView($designDirectionState)', $stateSource);
        self::assertStringContainsString("'match_keywords'", $pruneSource);
        self::assertStringContainsString("'visual_keywords'", $pruneSource);
        self::assertStringContainsString("'slimmed_for_view'", $pruneSource);
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

    public function testWorkspaceStateResolvesPublishQueueFromSharedBuildQueueBucket(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $methodSource = $this->extractMethodSource($source, 'buildWorkspaceState');

        self::assertStringContainsString("\$publishOperation = \$this->resolveWorkspaceQueueOperationState(\$activeOperation, \$activeOperations, 'publish');", $methodSource);
        self::assertStringContainsString("\$existingBuildQueueOperation = \\is_array(\$existingBuildQueueInfo)", $methodSource);
        self::assertStringContainsString("\$sharedBuildQueueOperations = [", $methodSource);
        self::assertStringContainsString("'image_asset' => true,", $methodSource);
        self::assertStringContainsString("\$buildQueueOperationCandidate = \$operationCandidate;", $methodSource);
        self::assertStringContainsString("buildOperationStageQueueInfoPayload(\$session, \$buildQueueOperationState, \$buildQueueOperation)", $methodSource);
        self::assertStringContainsString("\$buildQueueOperation => [\$buildQueueOperationState, \$buildQueueInfo]", $methodSource);
        self::assertStringContainsString("\$buildQueueOperation => \$buildQueueInfo", $methodSource);
    }

    public function testWorkspacePendingGenerationUsesPlanJsonBlocksNotLegacyLayouts(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        $workspaceState = $this->extractMethodSource($source, 'buildWorkspaceState');
        $resolvePending = $this->extractMethodSource($source, 'resolvePendingGenerationPageTypes');
        $completeGate = $this->extractMethodSource($source, 'isPageTypeGenerationComplete');

        self::assertStringContainsString('$pendingGenerationPageTypes = $this->resolvePendingGenerationPageTypes(', $workspaceState);
        self::assertStringNotContainsString('$pageTypeLayouts', $resolvePending);
        self::assertStringNotContainsString('$workspaceTrack', $resolvePending);
        self::assertStringNotContainsString('$pageTypeLayouts', $completeGate);
        self::assertStringNotContainsString('normalizeLayoutConfig', $completeGate);
        self::assertStringNotContainsString("'header'", $completeGate);
        self::assertStringNotContainsString("'footer'", $completeGate);
        self::assertStringNotContainsString("'content'", $completeGate);
        self::assertStringContainsString('$this->planJsonDynamicBlocks($page) !== []', $completeGate);
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

    private function extractConstArraySource(string $source, string $constName): string
    {
        $start = \strpos($source, 'private const ' . $constName . ' = [');
        self::assertIsInt($start, $constName . ' missing');
        $end = \strpos($source, "];", $start);
        self::assertIsInt($end, $constName . ' end missing');

        return \substr($source, $start, $end - $start + 2);
    }
}
