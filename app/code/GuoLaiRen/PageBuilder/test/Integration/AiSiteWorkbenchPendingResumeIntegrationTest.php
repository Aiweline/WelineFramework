<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

final class AiSiteWorkbenchPendingResumeIntegrationTest extends AbstractAiSiteWorkbenchIntegrationHarness
{
    public function testWorkspacePromptsBeforeContinuingPendingTasksOrObservingRunningOperation(): void
    {
        $planPanel = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml'
        );
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function startPlanGenerationForSelection(triggerBtn, selectedTypes, options)', $script, 'missing plan start function');
        self::assertStringContainsString('function confirmCurrentPlanAndMaybeBuild(', $script, 'missing confirm/build function');
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $planPanel, 'missing confirm plan button');
        self::assertStringNotContainsString('function maybeAutoStartBuildAfterWorkspaceState(data)', $script);
        self::assertStringNotContainsString('autoResumeActiveOperation', $script);
    }

    public function testConfirmPlanDoesNotUseStalePlanOverrideFlow(): void
    {
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $controller = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php'
        );
        $confirmStart = \strpos($controller, 'function handleConfirmPlan(');
        $confirmEnd = \strpos($controller, 'function handlePlanSse(');
        self::assertIsInt($confirmStart);
        self::assertIsInt($confirmEnd);
        $confirmSource = \substr($controller, $confirmStart, $confirmEnd - $confirmStart);

        self::assertStringNotContainsString('function promptPlanInputStaleConfirmation(data)', $script);
        self::assertStringNotContainsString("String(data.code || '') === 'PLAN_INPUT_STALE'", $script);
        self::assertStringNotContainsString("fields.force_confirm_stale_plan = '1';", $script);
        self::assertStringNotContainsString('confirmCurrentPlanAndMaybeBuild({ forceConfirmStalePlan: true });', $script);
        self::assertStringNotContainsString("confirm_only: '1'", $script);
        self::assertStringNotContainsString("start_build: '0'", $script);

        self::assertStringNotContainsString("getRequestBodyValue('force_confirm_stale_plan', 0)", $confirmSource);
        self::assertStringNotContainsString("'requires_confirmation' => true", $confirmSource);
        self::assertStringNotContainsString("'confirmation_code' => 'PLAN_INPUT_STALE_CONFIRM'", $confirmSource);
    }

    public function testResumePromptSuppressesTerminalOrCompleteGeneratedWorkspace(): void
    {
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function scheduleVisualEditResumePrompt()', $script);
        self::assertStringContainsString('function startOrObserveBuildFromVisualEditEntry()', $script);
        self::assertStringContainsString('BackendConfirm.show(confirmMessage, {', $script);
    }

    public function testWorkspaceStateHydrationNormalizesNestedOperationMapsBeforeRetryablePrompts(): void
    {
        $script = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();

        self::assertStringContainsString('function normalizeWorkspaceStateShape(state)', $script);
        self::assertStringContainsString('function ensureRetryableAiResumePromptState()', $script);
        self::assertStringContainsString("normalized.active_operations = normalized.active_operations && typeof normalized.active_operations === 'object'", $script);
        self::assertStringContainsString("'active_operations',", $script);
        self::assertStringContainsString('ensureRetryableAiResumePromptState();', $script);
        self::assertStringContainsString('workspaceState = normalizeWorkspaceStateShape(mergeWorkspaceStatePatch(workspaceState));', $script);
    }

    public function testSseErrorHandlersCloseAndDisposeTheStreamBeforeRetryUi(): void
    {
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $runtimeScript = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml'
        );

        self::assertEventBlocksDoNotContain($mainScript, "terminal.on('error'", 'closeOperationSource(');
        self::assertEventBlocksDoNotContain($mainScript, "terminal.on('failed'", 'closeOperationSource(');
        self::assertEventBlockContainsInOrder(
            $runtimeScript,
            "source.addEventListener('error'",
            'closeOperationSource(source);',
            'offerRetryForFailedOperation(operation, payload)'
        );
        self::assertEventBlockContainsInOrder(
            $runtimeScript,
            'source.onerror = function ()',
            'closeOperationSource(source);',
            'offerRetryForFailedOperation(operation, failurePayload)'
        );
        self::assertStringContainsString('function disposeOperationLiveStream(operation, summary)', $runtimeScript);
        self::assertStringContainsString('disposeOperationLiveStream(targetOperation, \'\');', $runtimeScript);
    }

    private static function assertEventBlockContainsInOrder(string $script, string $marker, string $first, string $second): void
    {
        $start = \strpos($script, $marker);
        self::assertNotFalse($start, 'Could not find event marker ' . $marker);
        $end = \strpos($script, "\n        });", $start);
        if ($end === false) {
            $end = \strpos($script, "\n        };", $start);
        }
        self::assertNotFalse($end, 'Could not find event block end for ' . $marker);
        $block = \substr($script, $start, $end - $start);
        $firstOffset = \strpos($block, $first);
        $secondOffset = \strpos($block, $second);
        self::assertNotFalse($firstOffset, $marker . ' missing ' . $first);
        self::assertNotFalse($secondOffset, $marker . ' missing ' . $second);
        self::assertLessThan($secondOffset, $firstOffset, $marker . ' must close stream before retry UI');
    }

    private static function assertEventBlocksDoNotContain(string $script, string $marker, string $forbidden): void
    {
        $offset = 0;
        $found = false;
        while (($start = \strpos($script, $marker, $offset)) !== false) {
            $found = true;
            $end = \strpos($script, "\n        });", $start);
            if ($end === false) {
                $end = \strpos($script, "\n        };", $start);
            }
            self::assertNotFalse($end, 'Could not find event block end for ' . $marker);
            $block = \substr($script, $start, $end - $start);
            self::assertStringNotContainsString($forbidden, $block, $marker . ' should not close SSE itself');
            $offset = $end + 1;
        }
        self::assertTrue($found, 'Could not find event marker ' . $marker);
    }
}
