<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentStageOneRetryFlowContractTest extends TestCase
{
    public function testIncompleteStageOnePlanReturnsToQueueCompletionGateForRetry(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());
        self::assertStringContainsString("AI_SITE_QUEUE_CONTENT_LIGHT_FIELDS = 'queue_id,type_id,pid,name,module,status,finished,start_at,end_at,biz_key,process'", $controllerSource);
        $runPlanOperation = $this->extractMethodSource($controllerSource, 'runQueuedPlanOperationFromWorkspaceStream');
        $retryBranch = $this->extractIfBlock($runPlanOperation, 'if ($retryablePlanFailures !== [])');

        self::assertStringContainsString('$this->updateActiveOperation(', $retryBranch);
        self::assertStringContainsString("'status' => 'error'", $retryBranch);
        self::assertStringContainsString("'retry_allowed' => 1", $retryBranch);
        self::assertStringContainsString('return;', $retryBranch);
        self::assertStringNotContainsString('throw new \\RuntimeException', $retryBranch);
        self::assertSame(1, \substr_count($runPlanOperation, 'if ($retryablePlanFailures !== [])'));

        $queueSource = (string)\file_get_contents((new ReflectionClass(AiSitePlanQueue::class))->getFileName());
        $execute = $this->extractMethodSource($queueSource, 'execute');
        self::assertStringContainsString('$retryablePlanMessages = $this->assertPlanQueueCompletionGate(', $execute);
        self::assertStringContainsString('$retryQueueId = $this->createPlanCompletionGateRetryQueue(', $execute);
        self::assertStringContainsString('$this->markPlanRetryScheduledInScope(', $execute);
        self::assertStringNotContainsString('resolvePlanJsonForCompletionGate', $queueSource);
        self::assertStringNotContainsString('persistRecoveredCompletionGatePlanJson', $queueSource);
    }

    public function testPlanOperationSseKeepsQueuedObserverStreamOpen(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());
        $observerPolicy = $this->extractMethodSource($controllerSource, 'shouldKeepQueuedObserverStreamOpen');

        self::assertStringContainsString("return \\trim(\$operation) === 'plan';", $observerPolicy);
        self::assertStringNotContainsString('return false;', $observerPolicy);

        self::assertStringContainsString('OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS = 250', $controllerSource);
        $observeDuplicateStream = $this->extractMethodSource($controllerSource, 'observeDuplicateOperationStream');
        self::assertStringContainsString('$pollIntervalMs = self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS;', $observeDuplicateStream);
        self::assertStringContainsString('$queueProgressChanged = $queueProgressBefore !== [', $observeDuplicateStream);
        self::assertStringContainsString('$idleLoops = 0;', $observeDuplicateStream);
        $deferredQueueObserver = $this->extractMethodSource($controllerSource, 'streamDeferredQueueProgressUntilTerminal');
        self::assertStringContainsString('$pollIntervalMs = self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS;', $deferredQueueObserver);
        self::assertStringContainsString('OBSERVER_QUEUE_PROGRESS_MAX_OBSERVE_MS', $deferredQueueObserver);

        $operationSse = $this->extractMethodSource($controllerSource, 'handleOperationSse');
        $queueObserverPos = \strpos($operationSse, 'if ($this->isAiSiteQueueBackedOperation($operation))');
        $claimPos = \strpos($operationSse, '$claim = $this->claimActiveOperationExecution(');
        self::assertIsInt($queueObserverPos);
        self::assertIsInt($claimPos);
        self::assertLessThan($claimPos, $queueObserverPos);
        $queueObserverBranch = \substr($operationSse, $queueObserverPos, $claimPos - $queueObserverPos);
        self::assertStringContainsString('streamDeferredQueueProgressUntilTerminal(', $queueObserverBranch);
        self::assertStringNotContainsString('claimActiveOperationExecution(', $queueObserverBranch);
    }

    public function testWorkspaceUiKeepsIncompletePlanGenerationRunningWhileQueueIsActive(): void
    {
        $moduleDir = \dirname((new ReflectionClass(AiSiteAgent::class))->getFileName(), 3);
        $workspaceScript = (string)\file_get_contents(
            $moduleDir . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );
        $blockingMessage = $this->extractFunctionSource($workspaceScript, 'getPhaseOnePlanBlockingErrorMessage');
        self::assertStringContainsString("hasRunningQueueForUi(state, 'plan')", $blockingMessage);
        self::assertStringContainsString("return '';", $blockingMessage);

        $renderQueueState = $this->extractFunctionSource($workspaceScript, 'renderQueueUiState');
        self::assertStringContainsString('var planStillRunning = queueKind === \'plan\'', $renderQueueState);
        self::assertStringContainsString('status = \'running\';', $renderQueueState);
        self::assertStringContainsString('!planStillRunning', $renderQueueState);

        $retryableGuards = $this->extractFunctionSource($workspaceScript, 'syncRetryableAiFailureActionGuards');
        self::assertStringContainsString('setPlanRetryButtonVisible(shouldShowPlanRetryButtonFromWorkspaceState(state));', $retryableGuards);
    }

    public function testStageOnePageFanoutProgressUsesCurrentStateOnly(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());
        $runPlanOperation = $this->extractMethodSource($controllerSource, 'runQueuedPlanOperationFromWorkspaceStream');
        self::assertStringContainsString("'stage1_page_progress'", $runPlanOperation);
        self::assertStringContainsString("'queue_process'", $runPlanOperation);
        self::assertStringContainsString('mergeStageOnePersistedPlanJson(', $runPlanOperation);
        self::assertStringContainsString("'plan_json' => \\is_array(\$scope['plan_json'] ?? null) ? \$scope['plan_json'] : []", $runPlanOperation);
        self::assertStringContainsString("'plan_structured' => \\is_array(\$scope['plan_structured'] ?? null) ? \$scope['plan_structured'] : []", $runPlanOperation);

        $moduleDir = \dirname((new ReflectionClass(AiSiteAgent::class))->getFileName(), 3);
        $serviceSource = (string)\file_get_contents(
            $moduleDir . '/Service/AiSiteExecutionBlueprintService.php'
        );
        self::assertStringContainsString('emitStageOnePageFanoutProgress(', $serviceSource);
        self::assertStringContainsString('Stage 1 page fanout: total ', $serviceSource);
        $summaryMethod = $this->extractMethodSource($serviceSource, 'summarizeStageOnePageFanoutProgress');
        self::assertStringNotContainsString("'page_statuses' =>", $summaryMethod);
        self::assertStringContainsString("'remaining_count' =>", $summaryMethod);
        self::assertStringContainsString("'details' =>", $summaryMethod);

        $workspaceScript = (string)\file_get_contents(
            $moduleDir . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml'
        );
        self::assertStringContainsString('publishPlanQueueLatestPageProgress', $workspaceScript);
        self::assertStringContainsString('normalizePlanQueuePageProgress', $workspaceScript);
        self::assertStringContainsString('renderPlanQueueTaskDetails', $workspaceScript);
        self::assertStringContainsString('latestEl.innerHTML =', $workspaceScript);
        self::assertStringNotContainsString('latestEl.innerHTML +=', $workspaceScript);
        self::assertStringContainsString(': null', $workspaceScript);

        $mainScript = (string)\file_get_contents(
            $moduleDir . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml'
        );
        self::assertStringContainsString('阶段一失败项，请重新重试', $mainScript);
        self::assertStringNotContainsString('阶段一失败项，确认方案已暂停', $mainScript);

        $queueWriterSource = (string)\file_get_contents((new ReflectionClass(QueueDbWriter::class))->getFileName());
        self::assertStringContainsString('mergeStageOnePageProgressIntoQueueContentPatch', $queueWriterSource);
        self::assertStringContainsString("'stage1_page_progress'", $queueWriterSource);

        $queueStateSource = (string)\file_get_contents((new ReflectionClass(AiSiteQueueSnapshotService::class))->getFileName());
        self::assertStringContainsString("'stage1_page_progress' => \$stageOnePageProgress", $queueStateSource);
    }

    private function extractMethodSource(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        return $this->extractFunctionLikeSource($source, $needle, 'method ' . $method);
    }

    private function extractFunctionSource(string $source, string $function): string
    {
        $needle = 'function ' . $function . '(';
        return $this->extractFunctionLikeSource($source, $needle, 'function ' . $function);
    }

    private function extractFunctionLikeSource(string $source, string $needle, string $label): string
    {
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

        self::fail('Unable to extract source for ' . $label);
    }

    private function extractIfBlock(string $source, string $needle): string
    {
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

        self::fail('Unable to extract if block for ' . $needle);
    }
}
