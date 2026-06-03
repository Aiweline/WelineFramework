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
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );

        self::assertStringContainsString('function startPlanGenerationForSelection(triggerBtn, selectedTypes, options)', $script, 'missing plan start function');
        self::assertStringContainsString('function confirmCurrentPlanAndMaybeBuild()', $script, 'missing confirm/build function');
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $planPanel, 'missing confirm plan button');
        self::assertStringNotContainsString('function maybeAutoStartBuildAfterWorkspaceState(data)', $script);
        self::assertStringNotContainsString('autoResumeActiveOperation', $script);
    }

    public function testStalePlanConfirmationPromptsAndUsesExplicitOverride(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );
        $controller = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php'
        );

        self::assertStringContainsString('function promptPlanInputStaleConfirmation(data)', $script);
        self::assertStringContainsString("String(data.code || '') === 'PLAN_INPUT_STALE'", $script);
        self::assertStringContainsString('window.BackendConfirm.show(confirmMessage, {', $script);
        self::assertStringContainsString("fields.force_confirm_stale_plan = '1';", $script);
        self::assertStringContainsString('confirmCurrentPlanAndMaybeBuild({ forceConfirmStalePlan: true });', $script);
        self::assertStringNotContainsString("return String(messages.planInputStaleRegenerateRequired || messages.planSchemeRebuildConfirmMessage || '');", $script);

        self::assertStringContainsString("getRequestBodyValue('force_confirm_stale_plan', 0)", $controller);
        self::assertStringContainsString("'requires_confirmation' => true", $controller);
        self::assertStringContainsString("'confirmation_code' => 'PLAN_INPUT_STALE_CONFIRM'", $controller);
        self::assertStringContainsString("'plan_confirmed_stale_input' =>", $controller);
    }

    public function testResumePromptSuppressesTerminalOrCompleteGeneratedWorkspace(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );

        self::assertStringContainsString('function allExpectedPageTypesHaveGeneratedSurface(workspaceState)', $script);
        self::assertStringContainsString('isTerminalActiveOperationStatus(activeStatus)', $script);
        self::assertStringContainsString('allExpectedPageTypesHaveGeneratedSurface(latestWorkspaceState)', $script);
        self::assertStringContainsString('function scheduleVisualEditResumePrompt()', $script);
        self::assertStringContainsString('function startOrObserveBuildFromVisualEditEntry()', $script);
        self::assertStringContainsString('BackendConfirm.show(confirmMessage, {', $script);
    }

    public function testWorkspaceStateHydrationNormalizesNestedOperationMapsBeforeRetryablePrompts(): void
    {
        $script = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );

        self::assertStringContainsString('function normalizeWorkspaceStateShape(state)', $script);
        self::assertStringContainsString('function ensureRetryableAiResumePromptState()', $script);
        self::assertStringContainsString("normalized.active_operations = normalized.active_operations && typeof normalized.active_operations === 'object'", $script);
        self::assertStringContainsString("'active_operations',", $script);
        self::assertStringContainsString('ensureRetryableAiResumePromptState();', $script);
        self::assertStringContainsString('workspaceState = normalizeWorkspaceStateShape(mergeWorkspaceStatePatch(workspaceState));', $script);
    }

    public function testSseErrorHandlersCloseAndDisposeTheStreamBeforeRetryUi(): void
    {
        $mainScript = (string)\file_get_contents(
            BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );
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
