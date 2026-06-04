<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueLogWriter;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueStateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiSiteAgentStageOneRetryFlowContractTest extends TestCase
{
    public function testStageOneEmptyAiStreamFailureMatchesCurrentProviderMessage(): void
    {
        $service = new AiSitePlanJsonGenerationService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'isEmptyAiStreamCompletionFailure');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(
            $service,
            new \RuntimeException('AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确')
        ));
        self::assertTrue($method->invoke(
            $service,
            new \RuntimeException('AI流式生成失败: AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确')
        ));
    }

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

    public function testPlanQueueAutomaticRetryStopsAfterThreeAttemptsAndRequiresManualRetry(): void
    {
        $queueSource = (string)\file_get_contents((new ReflectionClass(AiSitePlanQueue::class))->getFileName());

        self::assertStringContainsString('private const MAX_PLAN_QUEUE_ATTEMPTS = 3;', $queueSource);
        self::assertStringContainsString("private const CONTENT_AUTO_ATTEMPT_KEY = '_plan_auto_attempt';", $queueSource);
        self::assertStringContainsString("private const CONTENT_MAX_AUTO_ATTEMPTS_KEY = 'max_auto_attempts';", $queueSource);
        self::assertStringContainsString("private const CONTENT_AUTO_RETRY_SCHEDULED_KEY = '_auto_retry_scheduled';", $queueSource);
        self::assertStringContainsString("private const CONTENT_LAST_GATE_DECISION_KEY = 'last_gate_decision';", $queueSource);
        self::assertStringContainsString("private const CONTENT_LAST_GATE_REASON_KEY = 'last_gate_reason';", $queueSource);
        self::assertStringContainsString("private const CONTENT_LAST_GATE_AT_KEY = 'last_gate_at';", $queueSource);

        $execute = $this->extractMethodSource($queueSource, 'execute');
        self::assertStringContainsString('[$content, $autoAttempt, $maxAutoAttempts] = $this->beginPlanQueueAttempt(', $execute);
        self::assertStringContainsString('if ($autoAttempt > $maxAutoAttempts) {', $execute);
        self::assertStringContainsString('persistPlanQueueStopState(', $execute);
        self::assertStringContainsString('$this->markQueueStopped($queue, $message);', $execute);
        self::assertStringContainsString('$hasQueuedPlanMutation || ($hasQueuedPlanResume && !$this->isAutomaticPlanRetryContent($content))', $execute);

        $beginAttempt = $this->extractMethodSource($queueSource, 'beginPlanQueueAttempt');
        self::assertStringContainsString('if (!$this->isAutomaticPlanRetryContent($content)) {', $beginAttempt);
        self::assertStringContainsString('unset($content[self::CONTENT_AUTO_ATTEMPT_KEY], $content[self::CONTENT_MAX_AUTO_ATTEMPTS_KEY]);', $beginAttempt);
        self::assertStringContainsString('$content[self::CONTENT_MAX_AUTO_ATTEMPTS_KEY] = self::MAX_PLAN_QUEUE_ATTEMPTS;', $beginAttempt);
        self::assertStringContainsString('return [$content, $attempt, self::MAX_PLAN_QUEUE_ATTEMPTS];', $beginAttempt);

        $retryGuard = $this->extractMethodSource($queueSource, 'canScheduleAutomaticPlanRetry');
        self::assertStringContainsString('< self::MAX_PLAN_QUEUE_ATTEMPTS', $retryGuard);

        $sameQueueRetry = $this->extractMethodSource($queueSource, 'prepareSamePlanQueueRetry');
        self::assertStringContainsString('$content[self::CONTENT_AUTO_RETRY_SCHEDULED_KEY] = 1;', $sameQueueRetry);
        self::assertStringContainsString('$content[\'_force_rebuild\'] = 0;', $sameQueueRetry);

        $markStopped = $this->extractMethodSource($queueSource, 'markQueueStopped');
        self::assertStringContainsString("'manual_confirmation_required'", $markStopped);
        self::assertStringContainsString("'automatic_attempt_limit'", $markStopped);
        self::assertStringContainsString("'content' => (string)(\\json_encode(\$content, \\JSON_UNESCAPED_UNICODE)", $markStopped);
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
        self::assertStringContainsString('buildWorkspaceState($fresh, $adminId, 80, false)', $observeDuplicateStream);
        self::assertStringNotContainsString('replaceScope($fresh->getId(), $adminId, $scope)', $observeDuplicateStream);
        $deferredQueueObserver = $this->extractMethodSource($controllerSource, 'streamDeferredQueueProgressUntilTerminal');
        self::assertStringContainsString('$pollIntervalMs = self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS;', $deferredQueueObserver);
        self::assertStringContainsString('OBSERVER_QUEUE_PROGRESS_MAX_OBSERVE_MS', $deferredQueueObserver);
        self::assertStringContainsString('$lastStageOnePageProgressSignature = \'\';', $deferredQueueObserver);
        self::assertStringContainsString('emitObservedPlanStageOnePageProgressState(', $deferredQueueObserver);
        self::assertStringContainsString('buildWorkspaceState($fresh, $adminId, 80, false)', $deferredQueueObserver);
        $planProgressObserver = $this->extractMethodSource($controllerSource, 'emitObservedPlanStageOnePageProgressState');
        self::assertStringNotContainsString('decodeAiSiteQueueRowContent($queueRow)', $planProgressObserver);
        self::assertStringContainsString('stage1_page_progress', $planProgressObserver);
        self::assertStringContainsString('$queueState = $this->buildQueueObserverPublicState($queueRow);', $planProgressObserver);
        self::assertStringContainsString('$queueInfo = $this->queueObserverHelperService()->buildPanelPayload($queueRow, $queueState);', $planProgressObserver);
        self::assertStringContainsString('$signature === $lastSignature', $planProgressObserver);
        self::assertStringContainsString('$remaining = \array_key_exists(\'remaining_count\', $progress)', $planProgressObserver);
        self::assertStringContainsString('$running + $pending', $planProgressObserver);
        self::assertStringContainsString("\\str_starts_with(\$message, 'Stage 1 page fanout:')", $planProgressObserver);
        self::assertStringContainsString("\$sse->sendEvent('progress'", $planProgressObserver);

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
        $workspaceScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
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
        self::assertStringContainsString('stage1_page_progress', $runPlanOperation);
        self::assertStringContainsString("'queue_process'", $runPlanOperation);
        self::assertStringContainsString('mergeStageOnePersistedPlanJson(', $runPlanOperation);
        self::assertStringContainsString("\$sse->sendEvent('progress', \$payload);", $runPlanOperation);
        self::assertStringContainsString("'plan_generation_progress' => \$planGenerationProgress", $runPlanOperation);
        self::assertStringContainsString("sendEvent('plan_state'", $controllerSource);
        self::assertStringContainsString('pollWorkspaceStreamPlanState(', $controllerSource);
        self::assertStringNotContainsString('$structured !== [] ? $structured : $planJson', $controllerSource);

        $moduleDir = \dirname((new ReflectionClass(AiSiteAgent::class))->getFileName(), 3);
        $serviceSource = (string)\file_get_contents(
            $moduleDir . '/Service/AiSitePlanJsonGenerationService.php'
        );
        self::assertStringContainsString('emitStageOnePageFanoutProgress(', $serviceSource);
        self::assertStringContainsString('Stage 1 page fanout: total ', $serviceSource);
        self::assertStringContainsString("'concurrency' => \\max(0, (int)(\$fanoutProgress['concurrency'] ?? 0))", $serviceSource);
        self::assertStringContainsString("Env::get('pagebuilder.ai_site.max_http_concurrency', 5)", $serviceSource);
        self::assertStringContainsString('resolveStageOnePageFanoutConcurrency(\\count($pageTypes))', $serviceSource);
        self::assertStringContainsString('runCooperativeSessionTasksSettled($tasks', $serviceSource);
        self::assertStringContainsString("'concurrency' => \$concurrency", $serviceSource);
        self::assertStringContainsString('resolveStageOneBlockSegmentConcurrency(', $serviceSource);
        self::assertStringContainsString('supportsCooperativeConcurrency($segmentConcurrency)', $serviceSource);
        self::assertStringContainsString('FiberTaskRunner::currentPump() === null', $serviceSource);
        self::assertStringContainsString('runCooperativeSessionTasksSettled($segmentTasks', $serviceSource);
        self::assertStringContainsString('generateStageOneBlockSegmentByAi(', $serviceSource);
        $summaryMethod = $this->extractMethodSource($serviceSource, 'summarizeStageOnePageFanoutProgress');
        self::assertStringNotContainsString("'page_statuses' =>", $summaryMethod);
        self::assertStringContainsString("'concurrency' =>", $summaryMethod);
        self::assertStringContainsString("'remaining_count' =>", $summaryMethod);
        self::assertStringContainsString("'remaining_count' => \\count(\$groups['running']) + \\count(\$groups['pending'])", $summaryMethod);
        self::assertStringContainsString("'details' =>", $summaryMethod);

        $workspaceScript = (string)\file_get_contents(
            $moduleDir . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml'
        );
        self::assertStringContainsString('stage1_page_progress', $workspaceScript);
        self::assertStringContainsString('normalized.concurrency', $workspaceScript);
        self::assertStringContainsString("concurrency ' + concurrency", $workspaceScript);
        self::assertStringContainsString('function hasNonEmptyStageOneProgress(progress)', $workspaceScript);
        self::assertStringContainsString('function resolvePlanQueueLatestPageProgress(info)', $workspaceScript);
        self::assertStringContainsString('function mergeIncomingPlanQueueInfoWithRememberedProgress(info)', $workspaceScript);
        self::assertStringContainsString("['pending', 'queued', 'running', 'processing'].indexOf(status) >= 0", $workspaceScript);
        self::assertStringContainsString('publishPlanQueueLatestPageProgress(resolvePlanQueueLatestPageProgress(queueInfo), latestMessage)', $workspaceScript);
        self::assertStringContainsString('syncStageOnePlanPreviewFromWorkspaceState', $workspaceScript);

        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('function resolvePlanPageStatus(page)', $mainScript);
        self::assertStringContainsString('data-plan-node-status', $mainScript);
        self::assertStringNotContainsString('renderStageOnePageProgressPlaceholder', $mainScript);
        self::assertStringContainsString('阶段一失败项，请重新重试', $mainScript);
        self::assertStringNotContainsString('阶段一失败项，确认方案已暂停', $mainScript);

        $queueWriterSource = (string)\file_get_contents((new ReflectionClass(AiSiteQueueLogWriter::class))->getFileName());
        self::assertStringContainsString('class AiSiteQueueLogWriter', $queueWriterSource);
        $queueDbWriterSource = (string)\file_get_contents($moduleDir . '/Http/Sse/QueueDbWriter.php');
        self::assertStringContainsString('stage1_page_progress', $queueDbWriterSource);
        self::assertStringContainsString('mergeStageOnePageProgressIntoQueueContentPatch', $queueDbWriterSource);

        $queueStateSource = (string)\file_get_contents((new ReflectionClass(AiSiteQueueStateService::class))->getFileName());
        self::assertStringContainsString('stage1_page_progress', $queueStateSource);
    }

    public function testPlanWorkspaceDoesNotRecoverFromDetachedArtifactFiles(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());

        self::assertStringNotContainsString('emergencyLoadPlanArtifactsFromFilesystem', $controllerSource);
        self::assertStringNotContainsString("session-artifacts/' . \$sessionId . '/plan", $controllerSource);
        self::assertStringNotContainsString("artifactKey . '-'", $controllerSource);
        self::assertStringNotContainsString('latestFile', $controllerSource);
        self::assertStringContainsString('loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN, $planArtifactKeys)', $controllerSource);
        self::assertStringNotContainsString('hydrateStageOnePlanPayloadFromPlanJsonGeneration', $controllerSource);
    }

    public function testPageBuilderWorkspaceOwnsDomainWorkbenchRoutes(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());
        self::assertStringContainsString("getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-recommend-domain')", $controllerSource);
        self::assertStringContainsString("getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-check-domain')", $controllerSource);
        self::assertStringContainsString("getBackendUrlPath('pagebuilder/backend/ai-site-agent/domain-purchase-sse')", $controllerSource);
        self::assertStringNotContainsString("getBackendUrlPath('websites/backend/site-builder-agent/recommend-domain')", $controllerSource);
        self::assertStringNotContainsString("getBackendUrlPath('websites/backend/site-builder-agent/check-domain')", $controllerSource);
        self::assertStringNotContainsString("getBackendUrlPath('websites/backend/site-builder-agent/domain-purchase-sse')", $controllerSource);

        $recommendDomain = $this->extractMethodSource($controllerSource, 'postRecommendDomain');
        self::assertStringContainsString('recommendAvailableDomain(', $recommendDomain);
        self::assertStringContainsString('$deferAvailability', $recommendDomain);
        self::assertStringContainsString('buildDomainChoiceEnvironment()', $recommendDomain);
        self::assertStringContainsString("'local_registrar_account_id'", $recommendDomain);
        self::assertStringContainsString('LocalDomainPolicy::isManagedLocalDomain($requestHost)', $recommendDomain);
        self::assertStringContainsString('$this->isTruthyRequestFlag(\'fake_mode\')', $recommendDomain);

        $checkDomain = $this->extractMethodSource($controllerSource, 'postCheckDomain');
        self::assertStringContainsString('checkCandidateAvailability(', $checkDomain);
        self::assertStringContainsString("'available' => \$available", $checkDomain);

        $domainPurchaseSse = $this->extractMethodSource($controllerSource, 'getDomainPurchaseSse');
        self::assertStringContainsString('ensureLinkedWebsitesMirrorSession(', $domainPurchaseSse);
        self::assertStringContainsString('executeQueuedPurchase(', $domainPurchaseSse);
        self::assertStringContainsString('syncPageBuilderScopeFromLinkedWebsitesSession(', $domainPurchaseSse);

        $moduleDir = \dirname((new ReflectionClass(AiSiteAgent::class))->getFileName(), 3);
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString("var streamPublicId = String(data.public_id || publicId || linkedWorkbenchPublicId || '').trim();", $mainScript);
        self::assertStringContainsString('startDomainPurchaseStream(streamPublicId, executionToken);', $mainScript);
    }

    public function testProfileManualFlagsAreExplicitAndNotInferredFromFilledPatchValues(): void
    {
        $controllerSource = (string)\file_get_contents((new ReflectionClass(AiSiteAgent::class))->getFileName());

        $dropEmptyProfilePatch = $this->extractMethodSource($controllerSource, 'dropEmptyProfileIdentityPatchValues');
        self::assertStringContainsString("!\\is_array(\$payload['site_profile_manual'])", $dropEmptyProfilePatch);

        $startBuild = $this->extractMethodSource($controllerSource, 'handleStartBuild');
        self::assertStringContainsString('$scopePatch = $this->dropEmptyProfileIdentityPatchValues($scopePatch);', $startBuild);
        self::assertStringNotContainsString('$siteProfileManual = \\is_array($scopePatch', $startBuild);
        self::assertStringNotContainsString('$siteProfileManual[$manualField] = true;', $startBuild);

        $mutateScope = $this->extractMethodSource($controllerSource, 'mutateScope');
        self::assertStringContainsString('$payload = $this->dropEmptyProfileIdentityPatchValues($payload);', $mutateScope);
        self::assertStringNotContainsString('$siteProfileManual = \\is_array($payload', $mutateScope);
        self::assertStringNotContainsString('$siteProfileManual[$manualField] = true;', $mutateScope);

        $moduleDir = \dirname((new ReflectionClass(AiSiteAgent::class))->getFileName(), 3);
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('state.site_profile_manual = Object.assign({}, siteProfileManual);', $mainScript);
        self::assertStringContainsString('siteProfileManual[mapped] = true;', $mainScript);
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
