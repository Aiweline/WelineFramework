<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AiSiteWorkspaceVisualEditFrontendLoopTest extends TestCase
{
    public function testWorkspaceEditModalLoadsVirtualMetadataSavesConfigAndRefreshesPreview(): void
    {
        $script = $this->workspaceScript();

        $modalBody = $this->extractFunctionBody($script, 'openWorkspaceVisualComponentConfigModal');

        self::assertStringContainsString('var context = resolveWorkspaceVisualComponentContext(payload);', $modalBody);
        self::assertStringContainsString('var layoutUrlObj = new URL(visualComponentLayoutFieldsUrl, window.location.href);', $modalBody);
        self::assertStringContainsString("layoutUrlObj.searchParams.set('public_id', context.public_id);", $modalBody);
        self::assertStringContainsString("layoutUrlObj.searchParams.set('page_type', context.page_type);", $modalBody);
        self::assertStringContainsString('var layoutResult = await getJson(layoutUrlObj.toString());', $modalBody);

        self::assertStringContainsString('var metadataUrlObj = new URL(visualComponentMetadataUrl, window.location.href);', $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('public_id', context.public_id);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('page_type', context.page_type);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('component_code', context.component_code);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('style_code', styleCode);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('region', context.region);", $modalBody);
        self::assertStringContainsString("metadataUrlObj.searchParams.set('index', String(context.index));", $modalBody);
        self::assertStringContainsString('var metadataResult = await getJson(metadataUrlObj.toString());', $modalBody);

        self::assertStringContainsString('var saveResult = await postJson(visualComponentUpdateConfigUrl, {', $modalBody);
        self::assertStringContainsString('public_id: context.public_id,', $modalBody);
        self::assertStringContainsString('page_type: context.page_type,', $modalBody);
        self::assertStringContainsString('component_code: context.component_code,', $modalBody);
        self::assertStringContainsString('region: context.region,', $modalBody);
        self::assertStringContainsString('index: context.index,', $modalBody);
        self::assertStringContainsString('config: newConfig', $modalBody);
        self::assertStringContainsString('refreshEmbeddedPreviewPreservingScroll();', $modalBody);
    }

    public function testWorkspacePreviewClickAndMessageActionsOpenTheSharedBlockEditor(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $messageBody = $this->extractFunctionBody($script, 'bindWorkspacePreviewMessages');

        self::assertStringContainsString("doc.querySelectorAll('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString("if (action === 'edit-block' || action === 'open-editor') {", $bridgeBody);
        self::assertStringContainsString('openBlockEditorModal(payload);', $bridgeBody);

        self::assertStringContainsString("if (payload.type === 'pb-component-action') {", $messageBody);
        self::assertStringContainsString("if (String(payload.action || '') === 'edit-block' || String(payload.action || '') === 'open-editor') {", $messageBody);
        self::assertStringContainsString('openBlockEditorModal(payload);', $messageBody);
    }

    public function testBlockRefineUsesQueuedPartialPatchBeforeLegacySseFallback(): void
    {
        $script = $this->workspaceScript();
        $submitBody = $this->extractFunctionBody($script, 'submitRefineComponent');

        self::assertStringContainsString('if ((!startPatchBlockUrl && !startBlockRefineSseUrl)', $submitBody);
        self::assertStringContainsString('blockRefreshState.blockId = refineComponentState.componentCode;', $submitBody);
        self::assertStringContainsString('renderBlockStreamingState(messages.refineQueued);', $submitBody);
        self::assertStringContainsString('postForm(startPatchBlockUrl, {', $submitBody);
        self::assertStringContainsString('block_id: refineComponentState.blockId,', $submitBody);
        self::assertStringContainsString('component_code: refineComponentState.componentCode,', $submitBody);
        self::assertStringContainsString('window.PbAiOperationRunner.startFromResponse(data)', $submitBody);

        $queuedPatch = \strpos($submitBody, 'postForm(startPatchBlockUrl, {');
        $legacySseFallback = \strpos($submitBody, 'var opened = openBlockSseModal(');
        self::assertIsInt($queuedPatch);
        self::assertIsInt($legacySseFallback);
        self::assertLessThan(
            $legacySseFallback,
            $queuedPatch,
            'Block refine should enqueue block_partial_patch before falling back to the legacy block SSE flow.'
        );
    }

    public function testRuntimePartialPatchEventRefreshesOnlyCurrentBlock(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        self::assertStringContainsString("'build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine'", $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_applied'", $runtime);
        self::assertStringContainsString('hydrateWorkspaceFromState(payload.state);', $runtime);
        self::assertStringContainsString('previewBridge.setVirtualPagesByType(payload.state.virtual_pages_by_type);', $runtime);
        self::assertStringContainsString('runtimeFindNextBlock(pageType, pageState.blocks, blockId)', $runtime);
        self::assertStringContainsString('previewBridge.replaceCurrentBlockHtml(blockRefreshState.pageType, nextBlock)', $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_failed'", $runtime);
        self::assertStringContainsString("['regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine']", $runtime);
    }

    public function testBlockStreamingStateCleanupResolvesThroughWorkspaceApiBridge(): void
    {
        $script = $this->workspaceScript();
        $clearBody = $this->extractFunctionBody($script, 'clearBlockStreamingState');

        self::assertStringContainsString('var workspaceApi = window.__pbWorkspaceApi', $clearBody);
        self::assertStringContainsString("typeof workspaceApi.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('return workspaceApi.clearBlockStreamingState();', $clearBody);
        self::assertStringContainsString("typeof window.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('blockRefreshState.active = false;', $clearBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('workspaceApiRef.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
        self::assertStringContainsString('window.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
    }

    public function testBlockRefreshFallsBackToWorkspaceSnapshotWhenSseStateIsIncomplete(): void
    {
        $script = $this->workspaceScript();
        $snapshotBody = $this->extractFunctionBody($script, 'fetchWorkspaceSnapshotStateForBlockRefresh');
        $resolverBody = $this->extractFunctionBody($script, 'resolvePendingBlockSseResultTarget');
        $applyBody = $this->extractFunctionBody($script, 'applyPendingBlockSseResultWithSnapshot');

        self::assertStringContainsString('workspaceApi.fetchWorkspaceSnapshotState()', $snapshotBody);
        self::assertStringContainsString('hydrateWorkspaceFromState(workspaceState);', $snapshotBody);
        self::assertStringContainsString('mergeVirtualPagesByTypeState(workspaceState.virtual_pages_by_type);', $snapshotBody);

        self::assertStringContainsString('var payloadState = pendingBlockSseResult && pendingBlockSseResult.state', $resolverBody);
        self::assertStringContainsString('var nextBlock = pageState && Array.isArray(pageState.blocks)', $resolverBody);
        self::assertStringContainsString('findVirtualBlockInList(key, stateItem.blocks, targetBlockId);', $resolverBody);

        self::assertStringContainsString('if (resolved.effectiveState && resolved.nextBlock) {', $applyBody);
        self::assertStringContainsString('return fetchWorkspaceSnapshotStateForBlockRefresh().then(function () {', $applyBody);
        self::assertStringContainsString('return applyPendingBlockSseResult(options);', $applyBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function fetchWorkspaceSnapshotState()', $runtime);
        self::assertStringContainsString('workspaceApiRef.fetchWorkspaceSnapshotState = fetchWorkspaceSnapshotState;', $runtime);
    }

    public function testVisualPreviewEmitsComponentActionPayloadWithBlockIdentity(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $payloadBody = $this->extractFunctionBody($script, 'buildEmbeddedPreviewPayload');

        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString('var blockId = resolvePayloadBlockId(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('var componentCode = resolvePayloadComponentCode(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('component: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('component_code: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('block_id: blockId || componentCode,', $payloadBody);
        self::assertStringContainsString("page_type: String(", $payloadBody);
        self::assertStringContainsString("|| getActivePreviewPageType()", $payloadBody);
    }

    public function testVisualPreviewFrameUsesFixedDeviceViewportHeightInsteadOfDocumentScrollHeight(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncVisualPreviewFrameHeight');

        self::assertStringNotContainsString('function measureVisualPreviewDocumentHeight', $script);
        self::assertStringContainsString('var fixedHeight = getPreviewDeviceMinHeight();', $syncBody);
        self::assertStringContainsString("frame.style.minHeight = fixedHeight + 'px';", $syncBody);
        self::assertStringContainsString("frame.style.height = fixedHeight + 'px';", $syncBody);
        self::assertStringNotContainsString('scrollHeight', $syncBody);
        self::assertStringNotContainsString('clientHeight', $syncBody);
    }

    public function testWorkspaceStateReconcilesGeneratedArtifactsBeforeTaskSummary(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractFunctionBody($source, 'buildWorkspaceState');

        $virtualPagesAssigned = \strpos($body, '$normalized[\'virtual_pages_by_type\'] = $virtualPagesByType;');
        $reconcile = \strpos($body, '$normalized = $this->buildTaskService->reconcileGeneratedArtifactsWithTaskState($normalized);');
        $summary = \strpos($body, '$taskSummary = $this->buildTaskService->summarize($normalized);');

        self::assertIsInt($virtualPagesAssigned);
        self::assertIsInt($reconcile);
        self::assertIsInt($summary);
        self::assertGreaterThan(
            $virtualPagesAssigned,
            $reconcile,
            'Generated page and shared-component artifacts must be visible before task-state reconciliation.'
        );
        self::assertLessThan(
            $summary,
            $reconcile,
            'Workspace task progress must summarize the reconciled build_tasks state.'
        );
    }

    public function testPublishedVirtualThemeComponentsOverrideDefaultComponentRegistry(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        $virtualRenderCall = '$componentPath = $modelResolution[\'path\'];';
        $componentConfigAssign = '$this->assign(\'component_config\', $config);';
        self::assertStringContainsString($virtualRenderCall, $source);
        self::assertStringContainsString($componentConfigAssign, $source);
        self::assertStringContainsString('$componentPath = null;', $source);
        self::assertLessThan(
            \strpos($source, $componentConfigAssign),
            \strpos($source, $virtualRenderCall),
            'Resolved component path selection must happen after component config assignment and before template fetch.'
        );
    }

    public function testPublishCompletionStaysInWorkspaceInsteadOfRedirectingToPageIndex(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $finishBody = $this->extractFunctionBody($runtime, 'finishOperation');

        self::assertStringContainsString("if (operation === 'publish') {", $finishBody);
        self::assertLessThan(
            \strpos($finishBody, "var redirectUrl = '';"),
            \strpos($finishBody, "if (operation === 'publish') {"),
            'Publish completion must hydrate workspace state before generic redirect handling.'
        );
    }

    public function testPublishChecklistButtonIsBoundOnWorkspaceLoad(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindPublishStageLogic()');
        $boot = \strrpos($script, 'bindPublishStageLogic();');

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertGreaterThan(
            $definition,
            $boot,
            'Publish checklist and preview buttons must be bound after the function is defined.'
        );
    }

    public function testConfirmedTaskPlanButtonUsesResumeAwareBuildFlow(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');

        self::assertStringContainsString("var confirmTaskPlanBtn = document.getElementById('pb-ai-confirm-task-plan');", $bindBody);
        self::assertStringContainsString('ensureTaskPlanConfirmedBeforeBuild(currentPlanTriggerButton, currentPlanSelection, {});', $bindBody);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild(currentPlanTriggerButton, currentPlanSelection);', $bindBody);
    }

    public function testRetryableAiFailuresExposeManualContinueButtonsForAllStages(): void
    {
        $planPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml');
        self::assertIsString($planPanel);
        self::assertStringContainsString('id="pb-ai-retry-plan-failures"', $planPanel);
        self::assertStringContainsString('data-retryable-ai-operation="plan"', $planPanel);

        $taskPlanPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/task-plan-accordion-panel.phtml');
        self::assertIsString($taskPlanPanel);
        self::assertStringContainsString('id="pb-ai-retry-task-plan-failures"', $taskPlanPanel);
        self::assertStringContainsString('data-retryable-ai-operation="task_plan"', $taskPlanPanel);

        $visualEditPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($visualEditPanel);
        self::assertStringContainsString('id="pb-ai-retry-build-failures"', $visualEditPanel);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $visualEditPanel);

        $script = $this->workspaceScript();
        $syncButtonsBody = $this->extractFunctionBody($script, 'syncRetryableAiFailureButtons');
        self::assertStringContainsString("syncRetryableAiFailureButton('pb-ai-retry-plan-failures', 'plan', state)", $syncButtonsBody);
        self::assertStringContainsString("syncRetryableAiFailureButton('pb-ai-retry-task-plan-failures', 'task_plan', state)", $syncButtonsBody);
        self::assertStringContainsString("syncRetryableAiFailureButton('pb-ai-retry-build-failures', 'build', state)", $syncButtonsBody);

        $bindRetryBody = $this->extractFunctionBody($script, 'bindRetryableAiFailureButtons');
        self::assertStringContainsString('api.retryPhaseOnePlanGeneration({ forceRebuild: false });', $bindRetryBody);
        self::assertStringContainsString('api.retryPhaseTwoTaskPlanGeneration({ forceRebuild: false });', $bindRetryBody);
        self::assertStringContainsString('startPublishStageQualityRepair(buildRetryBtn);', $bindRetryBody);

        $countBody = $this->extractFunctionBody($script, 'getRetryableAiFailureCount');
        self::assertStringContainsString("if (normalizedOperation === 'build') {", $countBody);
        self::assertStringContainsString('state.latest_build_failed,', $countBody);
        self::assertStringContainsString('state.publish_blocked_by_latest_ai_failure,', $countBody);
    }

    public function testPublishStageStillKeepsPublishControlsAfterRemovingAiQualityRepairEntry(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $layout);

        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($visualEditCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $visualEditCard);

        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        self::assertIsString($publishCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $publishCard);
        self::assertStringContainsString('$pbAiLatestBuildFailed', $publishCard);
        self::assertStringContainsString('$pbAiPublishDisabled', $publishCard);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPublishStageLogic');
        $visualEditBindBody = $this->extractFunctionBody($script, 'bindVisualEditStageLogic');
        $syncBody = $this->extractFunctionBody($script, 'syncPublishRepairButtonDisabledState');
        $publishStartSyncBody = $this->extractFunctionBody($script, 'syncPublishStartButtonFromWorkspaceState');
        $terminalBody = $this->extractFunctionBody($script, 'markBuildOperationTerminalForUi');
        $resetBody = $this->extractFunctionBody($script, 'resetBuildStartUi');

        self::assertStringContainsString('bindPublishRepairButton();', $bindBody);
        self::assertStringContainsString('bindPublishRepairButton();', $visualEditBindBody);
        $publishRepairBindBody = $this->extractFunctionBody($script, 'bindPublishRepairButton');
        self::assertStringContainsString('bindRetryableAiFailureButtons();', $publishRepairBindBody);
        self::assertStringContainsString('syncRetryableAiFailureButtons(latestWorkspaceState || initialWorkspaceState || {});', $syncBody);
        self::assertStringNotContainsString("document.getElementById('pb-ai-rebuild-publish-quality')", $syncBody);
        self::assertStringContainsString('isOperationRunning = false;', $terminalBody);
        self::assertStringContainsString('isPublishBlockingOperationName(normalizedOperation)', $terminalBody);
        self::assertStringContainsString("previousOperation === 'build'", $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.active_operation = active;', $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.publish_blocked_by_latest_ai_failure = true;', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $terminalBody);
        self::assertStringContainsString('syncPublishStageEntryFromWorkspaceState(latestWorkspaceState);', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $resetBody);
        self::assertStringContainsString('hasPublishBlockingLatestBuildFailureFromWorkspaceState(state)', $publishStartSyncBody);
        self::assertStringContainsString("document.querySelectorAll('.pb-ai-set-stage[data-stage=\"publish\"]')", $publishStartSyncBody);
        self::assertStringContainsString('function hasPublishBlockingLatestBuildFailureFromWorkspaceState', $script);
        self::assertStringContainsString('function hasPublishBlockingAiOperationRunningFromWorkspaceState', $script);
        self::assertStringContainsString('messages.publishBlockedLatestBuildFailure', $visualEditBindBody);
        self::assertStringContainsString('hasPublishBlockingAiOperationRunning: function (state)', $script);
        self::assertStringContainsString('markBuildOperationTerminalForUi: markBuildOperationTerminalForUi', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function resolveLivePreviewBridge()', $runtime);
        self::assertStringContainsString('function syncTerminalActiveOperationForRuntimeUi(active)', $runtime);
        self::assertStringContainsString('var workspaceSnapshotUrl =', $runtime);
        self::assertStringContainsString('function startDeferredQueueStatePoll(operation)', $runtime);
        self::assertStringContainsString('submitWorkspaceForm(workspaceSnapshotUrl, { public_id: publicId })', $runtime);
        self::assertStringContainsString('startDeferredQueueStatePoll(operation);', $runtime);
        self::assertStringContainsString('stopDeferredQueueStatePoll();', $runtime);
        self::assertStringContainsString('function normalizeTerminalOperationStatus(status)', $runtime);
        self::assertStringContainsString("return 'error';", $runtime);
        self::assertStringContainsString('syncTerminalActiveOperationForRuntimeUi(data.active_operation);', $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(normalizedDoneOperation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(operation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString('function hasPublishBlockingAiStateForRuntime()', $runtime);
        self::assertStringContainsString('function syncPublishStartButtonForRuntime()', $runtime);
        self::assertStringContainsString("messages.publishBlockedLatestBuildFailure", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        self::assertStringContainsString('resolvePublishBlockingAiFailureFromWorkspaceState', $controller);
        self::assertStringContainsString('publish_blocked_by_latest_ai_failure', $controller);
        self::assertStringContainsString('Latest AI build completed successfully', $controller);
        self::assertStringContainsString('Current workspace is not ready to publish. Finish AI page generation first.', $controller);

        $sessionService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');
        self::assertIsString($sessionService);
        self::assertStringContainsString("'latest_build_failed'", $sessionService);
        self::assertStringContainsString("'latest_build_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_by_latest_ai_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_reason'", $sessionService);
    }

    public function testTaskPlanConfirmEndpointIsIdempotentForCompactedConfirmedBuildBlueprint(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractFunctionBody($source, 'handleConfirmTaskPlan');
        $idempotentGuard = \strpos($body, '$this->buildTaskService->hasConfirmedTaskPlanForBuild($scope)');
        $notReadyError = \strpos($body, "'TASK_PLAN_NOT_READY'");

        self::assertIsInt($idempotentGuard);
        self::assertIsInt($notReadyError);
        self::assertLessThan(
            $notReadyError,
            $idempotentGuard,
            'Repeated task-plan confirmation must reuse compacted confirmed build blueprints before reporting a missing draft.'
        );
    }

    private function workspaceScript(): string
    {
        $script = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($script);

        return $script;
    }

    private function extractFunctionBody(string $script, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = \strpos($script, $needle);
        self::assertIsInt($start, $functionName . ' must exist.');
        $next = \strpos($script, "\n    function ", $start + \strlen($needle));
        if ($next === false) {
            return \substr($script, $start);
        }

        return \substr($script, $start, $next - $start);
    }
}
