<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Http\Sse\SseWriter;

final class CapturingSseWriter extends SseWriter
{
    /**
     * @var list<array{event: string, data: mixed}>
     */
    public array $events = [];

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->events[] = ['event' => $event, 'data' => $data];

        return $this;
    }

    public function isAlive(): bool
    {
        return true;
    }
}

final class AiSiteAgentSseMarkerTest extends TestCase
{
    private const AI_CHUNK_FORWARDER_KEY = 'pagebuilder.ai.chunk.forwarder';

    /**
     * @return array{0: AiSiteAgent, 1: ReflectionMethod}
     */
    private function harnessForIsTaskPlanDraftMissing(bool $hasConfirmedTaskPlanForBuild = false): array
    {
        $buildTask = $this->createMock(AiSiteBuildTaskService::class);
        $buildTask->method('hasConfirmedTaskPlanForBuild')->willReturn($hasConfirmedTaskPlanForBuild);
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionClass(AiSiteAgent::class))->getProperty('buildTaskService');
        $prop->setAccessible(true);
        $prop->setValue($controller, $buildTask);
        $method = new ReflectionMethod(AiSiteAgent::class, 'isTaskPlanDraftMissing');
        $method->setAccessible(true);

        return [$controller, $method];
    }

    protected function tearDown(): void
    {
        RequestContext::remove(RequestContext::SSE_WRITER_KEY);
        RequestContext::remove(self::AI_CHUNK_FORWARDER_KEY);
        RequestContext::setId(null);
        parent::tearDown();
    }

    public function testClearAiChunkForwarderKeepsSseHandledMarker(): void
    {
        RequestContext::set(RequestContext::SSE_WRITER_KEY, new \stdClass());
        RequestContext::set(self::AI_CHUNK_FORWARDER_KEY, static function (): void {
        });

        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'clearAiChunkForwarder');
        $method->setAccessible(true);
        $method->invoke($controller);

        self::assertTrue((bool)RequestContext::get(RequestContext::SSE_WRITER_KEY, false));
        self::assertFalse(RequestContext::has(self::AI_CHUNK_FORWARDER_KEY));
    }

    public function testDetectBootstrapGateAllowsExplicitDeterministicFallback(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldRejectTaskPlanGenerationSource');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, ['fake_mode' => 0], 'ai', false));
        self::assertTrue((bool)$method->invoke($controller, ['fake_mode' => 0], 'deterministic', false));
        self::assertFalse((bool)$method->invoke($controller, ['fake_mode' => 0], 'deterministic', true));
        self::assertFalse((bool)$method->invoke($controller, ['fake_mode' => 1], 'deterministic', false));
    }

    public function testWorkspaceStreamTerminalOperationDetection(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $isActive = new ReflectionMethod(AiSiteAgent::class, 'isWorkspaceStreamOperationActive');
        $isTerminal = new ReflectionMethod(AiSiteAgent::class, 'isWorkspaceStreamOperationTerminal');
        $buildPayload = new ReflectionMethod(AiSiteAgent::class, 'buildWorkspaceStreamTerminalPayload');
        $isActive->setAccessible(true);
        $isTerminal->setAccessible(true);
        $buildPayload->setAccessible(true);

        self::assertTrue((bool)$isActive->invoke($controller, ['active_operation' => ['status' => 'queued']]));
        self::assertTrue((bool)$isActive->invoke($controller, ['active_operation' => ['status' => 'running']]));
        self::assertFalse((bool)$isActive->invoke($controller, ['active_operation' => ['status' => 'done']]));

        foreach (['done', 'error', 'cancelled'] as $status) {
            self::assertTrue(
                (bool)$isTerminal->invoke($controller, ['active_operation' => ['status' => $status]]),
                $status . ' must close the workspace SSE stream once an active operation reaches a terminal state.'
            );
        }
        self::assertFalse((bool)$isTerminal->invoke($controller, ['active_operation' => ['status' => 'running']]));

        $donePayload = $buildPayload->invoke($controller, [
            'public_id' => 'pb-terminal-test',
            'active_operation' => ['status' => 'done', 'message' => 'plan done'],
        ], 42);
        self::assertSame('done', $donePayload['terminal_status']);
        self::assertTrue((bool)$donePayload['success']);
        self::assertSame('plan done', $donePayload['message']);
        self::assertSame(42, $donePayload['last_event_id']);

        $errorPayload = $buildPayload->invoke($controller, [
            'public_id' => 'pb-terminal-test',
            'active_operation' => ['status' => 'error'],
        ], 43);
        self::assertSame('error', $errorPayload['terminal_status']);
        self::assertFalse((bool)$errorPayload['success']);
        self::assertSame(43, $errorPayload['last_event_id']);
    }

    public function testObservedQueueErrorStatusWithoutFinishedStillTerminatesObserver(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $isTerminal = new ReflectionMethod(AiSiteAgent::class, 'isObservedQueueTerminal');
        $isInProgress = new ReflectionMethod(AiSiteAgent::class, 'isObservedQueueInProgress');
        $isTerminal->setAccessible(true);
        $isInProgress->setAccessible(true);

        $queueRow = [
            'status' => 'error',
            'pid' => 0,
            'finished' => 0,
            'end_at' => null,
            'process' => 'AI 正在生成内容，正文流不写入队列日志',
        ];

        self::assertTrue((bool)$isTerminal->invoke($controller, $queueRow));
        self::assertFalse((bool)$isInProgress->invoke($controller, $queueRow));
    }

    public function testRuntimeUsesQueueFailureDetailForFailedSseDonePayload(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml'
        );

        self::assertIsString($source);
        self::assertStringContainsString('function resolveQueueFailureMessage(payload, operation)', $source);
        self::assertStringContainsString('function readStageQueueFailureMessage(queueInfo)', $source);
        self::assertStringContainsString('isStageQueueFailed(planQueueStatus)', $source);
        self::assertStringContainsString('resolveQueueFailureMessage(normalizedDonePayload, normalizedDoneOperation)', $source);
        self::assertStringContainsString('hydrateWorkspaceFromState(normalizedDonePayload.state)', $source);
        self::assertStringContainsString('updateStageStatusSummary(normalizedDonePayload.state)', $source);
    }

    public function testBlockConfigReplacementOnlyTouchesSelectedPageBlock(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'replaceCurrentPageBlockInVirtualPages');
        $method->setAccessible(true);

        $virtualPages = [
            'home' => [
                'blocks' => [
                    [
                        'block_id' => 'home-site-header',
                        'type' => 'site_header',
                        'html' => '<header>Home before</header>',
                        'config' => ['site_title' => 'Home before'],
                    ],
                    [
                        'block_id' => 'home-hero',
                        'type' => 'hero',
                        'html' => '<section>Hero before</section>',
                        'config' => ['headline' => 'Hero before'],
                    ],
                ],
            ],
            'about' => [
                'blocks' => [
                    [
                        'block_id' => 'about-site-header',
                        'type' => 'site_header',
                        'html' => '<header>About before</header>',
                        'config' => ['site_title' => 'About before'],
                    ],
                ],
            ],
        ];

        $result = $method->invoke(
            $controller,
            $virtualPages,
            'home',
            'home-site-header',
            [
                'block_id' => 'home-site-header',
                'type' => 'site_header',
                'html' => '<header>Home tuned</header>',
                'config' => ['site_title' => 'Home tuned'],
            ],
            '2026-04-21 15:20:00'
        );

        self::assertSame('Home tuned', $result['home']['blocks'][0]['config']['site_title']);
        self::assertSame('<header>Home tuned</header>', $result['home']['blocks'][0]['html']);
        self::assertSame('Hero before', $result['home']['blocks'][1]['config']['headline']);
        self::assertSame('About before', $result['about']['blocks'][0]['config']['site_title']);
        self::assertSame('<header>About before</header>', $result['about']['blocks'][0]['html']);
        self::assertSame('2026-04-21 15:20:00', $result['home']['last_generated_at']);
        self::assertArrayNotHasKey('last_generated_at', $result['about']);
    }

    public function testQueueObserverPanelPayloadContainsCurrentQueueDetails(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildQueueObserverPanelPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, [
            'queue_id' => 143,
            'name' => 'PageBuilder plan',
            'module' => 'GuoLaiRen_PageBuilder',
            'biz_key' => 'glr_aisite:session:987:queue_slot:planning',
            'status' => 'running',
            'pid' => 48104,
            'type_id' => 6,
            'finished' => 0,
            'start_at' => '2026-04-21 02:15:53',
            'end_at' => '2026-04-21 02:16:04',
            'process' => 'AI 流式生成中... (+33 B)',
            'result' => "QUEUE 开始执行\nLOG AI 生成中\n",
            'content' => \json_encode([
                'job_key' => 'glr_aisite:session:987:job:stage1.requirement_expand',
                'job_type' => 'stage1.requirement_expand',
                'operation' => 'plan',
                'status' => 'running',
                'token' => 'token-abc',
                'token_usage' => [
                    'input_tokens' => 1200,
                    'output_tokens' => 340,
                    'total_tokens' => 1540,
                ],
            ], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(143, $payload['queue_id']);
        self::assertSame('running', $payload['snapshot']['status']);
        self::assertSame('glr_aisite:session:987:job:stage1.requirement_expand', $payload['snapshot']['job_key']);
        self::assertSame('stage1.requirement_expand', $payload['snapshot']['job_type']);
        self::assertSame('token-abc', $payload['snapshot']['token']);
        self::assertSame(1200, $payload['snapshot']['token_usage']['input_tokens']);
        self::assertSame(340, $payload['snapshot']['token_usage']['output_tokens']);
        self::assertSame(1540, $payload['snapshot']['token_usage']['total_tokens']);
        self::assertSame('AI 流式生成中... (+33 B)', $payload['process']);
        self::assertStringContainsString('已省略 2 行 AI 正文输出', $payload['result_log']);
    }

    public function testStageOneRequirementExpandQueueEnvelopeUsesJobFields(): void
    {
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getId')->willReturn(321);

        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildOperationQueueEnvelope');
        $method->setAccessible(true);

        $envelope = $method->invoke($controller, $session, 'plan', 'token-abc', 'queued');

        self::assertSame('glr_aisite:session:321:job:stage1.requirement_expand', $envelope['job_key']);
        self::assertSame('stage1.requirement_expand', $envelope['job_type']);
        self::assertSame('queued', $envelope['status']);
        self::assertSame('token-abc', $envelope['token']);
    }

    public function testObservedQueueSignalsExposePanelSyncFields(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'forwardObservedQueueSignals');
        $method->setAccessible(true);
        $writer = new CapturingSseWriter();

        $method->invoke(
            $controller,
            $writer,
            [
                'queue_id' => 143,
                'name' => 'PageBuilder plan',
                'module' => 'GuoLaiRen_PageBuilder',
                'biz_key' => 'glr_aisite:session:987:queue_slot:planning',
                'status' => 'running',
                'pid' => 48104,
                'type_id' => 6,
                'finished' => 0,
                'process' => 'AI 流式生成中... (+33 B)',
                'result' => "QUEUE 开始执行\nLOG AI 生成中\n",
                'content' => \json_encode([
                    'operation' => 'plan',
                    'token_usage' => [
                        'input_tokens' => 600,
                        'output_tokens' => 180,
                        'total_tokens' => 780,
                    ],
                ], \JSON_THROW_ON_ERROR),
            ],
            'plan',
            '',
            0,
            'pending',
            0
        );

        $infoEvents = \array_values(\array_filter(
            $writer->events,
            static fn(array $event): bool => $event['event'] === 'info'
        ));
        $chunkEvents = \array_values(\array_filter(
            $writer->events,
            static fn(array $event): bool => $event['event'] === 'chunk'
        ));

        self::assertNotEmpty($infoEvents);
        self::assertIsArray($infoEvents[0]['data']);
        self::assertSame('queue_info', $infoEvents[0]['data']['progress_kind']);
        self::assertArrayHasKey('queue_info', $infoEvents[0]['data']);
        self::assertSame('running', $infoEvents[0]['data']['queue_info']['snapshot']['status']);
        self::assertSame(600, $infoEvents[0]['data']['token_usage']['input_tokens']);
        self::assertSame(180, $infoEvents[0]['data']['queue_info']['snapshot']['token_usage']['output_tokens']);
        $panelUpdateEvents = \array_values(\array_filter(
            $infoEvents,
            static fn(array $event): bool => \is_array($event['data']) && !empty($event['data']['queue_panel_update'])
        ));
        self::assertNotEmpty($panelUpdateEvents);
        self::assertSame('AI 流式生成中... (+33 B)', $panelUpdateEvents[0]['data']['queue_process']);

        self::assertSame([], $chunkEvents, '规划类队列不再把 queue.result 作为 chunk SSE 回放');
        self::assertStringContainsString('已省略 2 行 AI 正文输出', (string)$panelUpdateEvents[0]['data']['queue_info']['result_log']);
        self::assertSame(780, $panelUpdateEvents[0]['data']['token_usage']['total_tokens']);
        self::assertSame(780, $panelUpdateEvents[0]['data']['queue_info']['snapshot']['token_usage']['total_tokens']);
    }

    public function testDoneQueueSuppressesReplayedObservedFailureEvents(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'shouldSuppressObservedErrorEventForDoneQueue');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($controller, ['event_type' => 'operation_failed'], 'error', ['status' => 'done']));
        self::assertTrue((bool)$method->invoke($controller, ['event_type' => 'error'], 'error', ['status' => 'done']));
        self::assertFalse((bool)$method->invoke($controller, ['event_type' => 'progress'], 'info', ['status' => 'done']));
        self::assertFalse((bool)$method->invoke($controller, ['event_type' => 'operation_failed'], 'error', ['status' => 'running']));
        self::assertFalse((bool)$method->invoke($controller, ['event_type' => 'operation_failed'], 'error', null));

        $controllerSource = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controllerSource);
        self::assertStringContainsString(
            'forwardObservedOperationEvents($sse, $session, $adminId, $recentEvents, $lastEventId, $initialQueueRow)',
            $controllerSource
        );
        self::assertStringContainsString(
            'forwardObservedOperationEvents($sse, $session, $adminId, $newEvents, $lastEventId, $queueRow)',
            $controllerSource
        );
    }

    public function testOperationSseClaimedDispatcherKeepsQueueBackedOperationsObserverOnly(): void
    {
        $controllerSource = \file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php'
        );

        self::assertIsString($controllerSource);
        self::assertStringContainsString(
            'if ($this->isAiSiteQueueBackedOperation($operation))',
            $controllerSource,
            'operation-sse claimed-operation dispatcher must not execute queue-backed AI operations directly.'
        );
        self::assertStringContainsString('队列型 AI 操作仅由系统调度器执行。', $controllerSource);
    }

    public function testPhaseOneQueuePanelIsSubscribedToOperationSsePayloads(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $phaseScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        $runtimeScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');

        self::assertIsString($phaseScript);
        self::assertIsString($runtimeScript);
        self::assertStringContainsString('syncFromSsePayload', $phaseScript);
        self::assertStringContainsString('mergePlanQueueInfoFromSsePayload', $phaseScript);
        self::assertStringContainsString('renderQueueInfoSummary', $phaseScript);
        self::assertStringContainsString('resolveTokenUsage', $phaseScript);
        self::assertStringContainsString('data-queue-info-list="stage1"', $phaseScript);
        self::assertStringContainsString("data-queue-info-field=\"' + escapeHtml(key) + '\"", $phaseScript);
        self::assertStringContainsString('data-token-usage-field', $phaseScript);
        self::assertStringContainsString("'job_status'", $phaseScript);
        self::assertStringContainsString('payload.progress_kind', $phaseScript);
        self::assertStringContainsString('input_tokens', $phaseScript);
        self::assertStringContainsString('output_tokens', $phaseScript);
        self::assertStringContainsString('total_tokens', $phaseScript);
        self::assertStringContainsString('prompt_tokens', $phaseScript);
        self::assertStringContainsString('completion_tokens', $phaseScript);
        self::assertStringContainsString('queue_result_delta', $phaseScript);
        self::assertStringContainsString('__pbPhase1TaskProgress.syncFromSsePayload(operation, payload || {}, eventKind)', $runtimeScript);
    }

    public function testRuntimeStagePresentationLetsDoneQueueOverrideStaleActiveFailure(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $scriptPaths = [
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml',
            $moduleRoot . '/view/tpl/zh_Hans_CN/templates/Backend/AiSiteAgent/workspace/com_script-runtime.phtml',
        ];

        foreach ($scriptPaths as $scriptPath) {
            $runtimeScript = \file_get_contents($scriptPath);

            self::assertIsString($runtimeScript);
            self::assertStringContainsString("['done', 'complete', 'completed']", $runtimeScript);

            $planDonePos = \strpos($runtimeScript, 'if (isStageQueueDone(planQueueStatus))');
            $planErrorPos = \strpos($runtimeScript, "if (activeOp === 'plan' && activeStatus === 'error')");
            $taskDonePos = \strpos($runtimeScript, 'if (isStageQueueDone(taskPlanQueueStatus))');
            $taskErrorPos = \strpos($runtimeScript, "if (activeOp === 'task_plan' && activeStatus === 'error')");
            $buildDonePos = \strpos($runtimeScript, 'if (isStageQueueDone(buildQueueStatus))');
            $buildErrorPos = \strpos($runtimeScript, "if (activeOp === 'build' && activeStatus === 'error')");

            self::assertNotFalse($planDonePos, $scriptPath);
            self::assertNotFalse($planErrorPos, $scriptPath);
            self::assertNotFalse($taskDonePos, $scriptPath);
            self::assertNotFalse($taskErrorPos, $scriptPath);
            self::assertNotFalse($buildDonePos, $scriptPath);
            self::assertNotFalse($buildErrorPos, $scriptPath);

            self::assertLessThan($planErrorPos, $planDonePos, $scriptPath);
            self::assertLessThan($taskErrorPos, $taskDonePos, $scriptPath);
            self::assertLessThan($buildErrorPos, $buildDonePos, $scriptPath);
        }
    }

    public function testPageTypeSelectionDoesNotLetDefaultServerStateOverwriteLocalCustomChoice(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $mainScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($mainScript);
        self::assertIsString($controllerSource);
        self::assertStringContainsString('var pageTypesSelectionLocallyCustomized = !!pageTypesUserCustomized;', $mainScript);
        self::assertStringContainsString('var skipServerOverwrite = pageTypesSelectionLocallyCustomized && !incomingMatchesCurrentSelection;', $mainScript);
        self::assertStringContainsString("page_types_user_customized: (pageTypesUserCustomized || pageTypesSelectionLocallyCustomized) ? 1 : 0", $mainScript);
        self::assertStringContainsString('serverPageTypesFallback = normalizedTypes.slice();', $mainScript);
        self::assertStringContainsString('$scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;', $controllerSource);
        self::assertStringNotContainsString("\$scopePatch['page_types'] = \$executionBlueprintDraft['page_types'];", $controllerSource);
        $flagPos = \strpos($controllerSource, '$scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;');
        $normalizePos = \strpos($controllerSource, '$scope = $this->scopeCompatibilityService->normalizeScope', $flagPos);
        self::assertNotFalse($flagPos);
        self::assertNotFalse($normalizePos);
        self::assertLessThan(
            $normalizePos,
            $flagPos
        );
    }

    public function testQueueInfoListsExposeTokenUsageColumns(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $phaseOneScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        $phaseTwoScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase2-queue-progress.phtml');

        self::assertIsString($phaseOneScript);
        self::assertIsString($phaseTwoScript);

        foreach ([$phaseOneScript, $phaseTwoScript] as $script) {
            self::assertStringContainsString('data-token-usage-field', $script);
            self::assertStringContainsString("renderQueueSummaryItem('input_tokens'", $script);
            self::assertStringContainsString("renderQueueSummaryItem('output_tokens'", $script);
            self::assertStringContainsString("renderQueueSummaryItem('total_tokens'", $script);
        }

        self::assertStringContainsString('pb-ai-plan-queue-token-summary', $phaseOneScript);
        self::assertStringContainsString('pb-ai-phase2-queue-token-summary', $phaseTwoScript);
        self::assertStringContainsString('pickTokenCount(usage, [\'input_tokens\', \'prompt_tokens\'])', $phaseTwoScript);
    }

    public function testPlanQueueDuplicateStreamRequiresPersistedStageOnePlan(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $planQueueSource = \file_get_contents($moduleRoot . '/Queue/AiSitePlanQueue.php');

        self::assertIsString($controllerSource);
        self::assertIsString($planQueueSource);
        self::assertStringContainsString("string \$claimSource = 'operation_sse'", $controllerSource);
        self::assertStringContainsString("&& \$claimSource === 'queue'", $controllerSource);
        self::assertStringContainsString('&& !$this->scopeHasPersistedStageOnePlan($scope)', $controllerSource);
        self::assertStringContainsString("'claimed_by' => \$claimSource", $controllerSource);
        self::assertStringContainsString('[$session, $adminId, $effectiveExecutionToken, \'plan\', \'queue\']', $planQueueSource);
    }

    public function testStageTwoTaskProgressUiSupportsRealtimeSemanticStatuses(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $mainScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        $runtimeScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($layout);
        self::assertIsString($mainScript);
        self::assertIsString($runtimeScript);
        self::assertIsString($controllerSource);

        foreach (['todo', 'queued', 'running', 'done', 'failed', 'stale', 'cancelled'] as $status) {
            self::assertStringContainsString("__('{$status}')", $layout);
            self::assertStringContainsString("'{$status}'", $runtimeScript);
        }

        self::assertStringContainsString('data-task-progress-summary="stage2"', $layout);
        self::assertStringContainsString('pb-ai-task-queued', $layout);
        self::assertStringContainsString('pb-ai-task-failed', $layout);
        self::assertStringContainsString('pb-ai-task-stale', $layout);
        self::assertStringContainsString('pb-ai-task-cancelled', $layout);
        self::assertStringContainsString('pb-ai-task-ai-indicator', $layout);
        self::assertStringContainsString('pb-ai-task-ai-indicator--active', $layout);
        self::assertStringContainsString('pb-ai-task-ai-dot', $layout);
        self::assertStringContainsString('data-ai-indicator-text', $layout);
        self::assertStringContainsString('@keyframes pb-ai-task-ai-blink', $layout);
        self::assertStringContainsString('TASK_PROGRESS_STATUSES', $runtimeScript);
        self::assertStringContainsString('normalizeTaskProgressStatus', $runtimeScript);
        self::assertStringContainsString('__pbTaskProgressGeneratingState', $runtimeScript);
        self::assertStringContainsString('function isQueueTaskProgressGenerating(source)', $runtimeScript);
        self::assertStringContainsString('taskProgressGeneratingState.active', $runtimeScript);
        self::assertStringContainsString('ai_generating', $runtimeScript);
        self::assertStringContainsString('function refreshEmbeddedPreviewFrame(delayMs)', $runtimeScript);
        self::assertStringContainsString("url.searchParams.set('_pb_build_done'", $runtimeScript);
        self::assertStringContainsString('window.PbAiWorkspacePreview.syncPreviewMetaFromState(payload.state)', $runtimeScript);
        self::assertStringContainsString('function renderTaskProgressGroupSummary(group)', $runtimeScript);
        self::assertStringContainsString("String(workspaceState.progress_kind || '') === 'task_progress'", $runtimeScript);
        self::assertStringContainsString('updateTaskSummaryFromState(payload)', $runtimeScript);
        self::assertStringContainsString('renderTaskStatusCountBadges(group)', $runtimeScript);
        self::assertStringContainsString('renderTaskProgressGroupSummary(group)', $runtimeScript);
        self::assertStringContainsString('var livePreviewBridge = resolvePreviewBridge();', $mainScript);
        self::assertStringContainsString('previewBridge = resolvePreviewBridge();', $mainScript);
        self::assertStringContainsString('window.PbAiWorkspacePreview.syncPreviewMetaFromState(payload.state)', $runtimeScript);
        self::assertStringContainsString('window.PbAiWorkspacePreview.switchPreviewByType(pageType);', $runtimeScript);
        self::assertStringContainsString('$groupSummaryLabel = (string)__(\'待处理\');', $layout);
        self::assertStringContainsString('htmlspecialchars($groupSummaryLabel', $layout);
        foreach (['总任务', '待处理', '排队中', '进行中', '已完成'] as $taskMetricLabel) {
            self::assertStringContainsString("'" . $taskMetricLabel . "'", $mainScript);
        }
        self::assertStringContainsString('if (doneEl) { doneEl.textContent = String(counts.done || 0); }', $runtimeScript);
        self::assertStringContainsString('private function emitObservedBuildTaskProgressSnapshot', $controllerSource);
        self::assertStringContainsString('private function buildTaskProgressSnapshotPayload', $controllerSource);
        self::assertStringContainsString("'progress_kind' => 'task_progress'", $controllerSource);
        self::assertStringContainsString('\'ai_generating\' => $aiGenerating', $controllerSource);
        self::assertStringContainsString('private function buildObservedProgressPayload', $controllerSource);
    }

    public function testConfirmedTaskPlanIsNotTreatedAsMissingWhenDraftWasCompacted(): void
    {
        [$controller, $method] = $this->harnessForIsTaskPlanDraftMissing();

        $missing = (bool)$method->invoke($controller, [
            'task_plan_confirmed' => 0,
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'confirmed' => [
                    'pages' => [
                        ['page_type' => 'home_page', 'sections' => []],
                    ],
                ],
                'confirmed_markdown' => '',
                'confirmed_at' => '2026-04-24 12:30:00',
                'confirmed_signature' => 'confirmed-sig',
            ],
        ]);

        self::assertFalse($missing, 'Confirmed task plan artifacts must stop task-plan queue auto-rerun even when draft fields were compacted.');
    }

    public function testTaskPlanIsMissingOnlyWhenDraftAndConfirmedArtifactsAreEmpty(): void
    {
        [$controller, $method] = $this->harnessForIsTaskPlanDraftMissing();

        self::assertTrue((bool)$method->invoke($controller, [
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'confirmed' => [],
                'confirmed_markdown' => '',
            ],
        ]));

        self::assertFalse((bool)$method->invoke($controller, [
            'task_plan_markdown' => '',
            'task_plan_structured' => ['pages' => []],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
            ],
        ]));
    }

    public function testTaskPlanNotMissingWhenTaskPlanConfirmedFlagSet(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isTaskPlanDraftMissing');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, [
            'task_plan_confirmed' => 1,
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'confirmed' => [],
                'confirmed_markdown' => '',
            ],
        ]));
    }

    public function testTaskPlanNotMissingWhenTaskPlanSummaryHasCounts(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isTaskPlanDraftMissing');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, [
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'task_plan_summary' => [
                'page_task_count' => 3,
                'shared_task_count' => 0,
                'signature' => '',
            ],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
            ],
        ]));
    }

    public function testTaskPlanNotMissingWhenBuildServiceReportsConfirmedExecutionPlan(): void
    {
        [$controller, $method] = $this->harnessForIsTaskPlanDraftMissing(true);

        self::assertFalse((bool)$method->invoke($controller, [
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
            ],
        ]));
    }

    public function testTaskPlanNotMissingWhenVirtualThemePlanRootPageTasksPopulated(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'isTaskPlanDraftMissing');
        $method->setAccessible(true);

        self::assertFalse((bool)$method->invoke($controller, [
            'task_plan_markdown' => '',
            'task_plan_structured' => [],
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'page_tasks' => [
                    'home_page' => [
                        ['task_key' => 'page:home_page:hero'],
                    ],
                ],
            ],
        ]));
    }

    public function testWorkspacePollingPayloadUsesSameStatusEnvelopeAsSseSnapshot(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $pollingMethod = new ReflectionMethod(AiSiteAgent::class, 'decorateWorkspaceStateWithPollingPayload');
        $pollingMethod->setAccessible(true);
        $sseMethod = new ReflectionMethod(AiSiteAgent::class, 'buildWorkspaceSseStatePayload');
        $sseMethod->setAccessible(true);

        $state = [
            'public_id' => 'pub-123',
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'stage_label' => 'Plan',
            'workspace_status' => 'building',
            'publish_status' => 'draft',
            'can_publish' => false,
            'workspace_track' => 'html_blocks',
            'site_ready' => 1,
            'website_id' => 9,
            'virtual_theme_id' => 11,
            'draft_website_id' => 9,
            'preview_page_type' => 'home_page',
            'plan_confirmed' => 0,
            'plan_confirmed_at' => '',
            'has_execution_blueprint' => true,
            'task_plan_confirmed' => 0,
            'task_plan_confirmed_at' => '',
            'has_virtual_theme_plan' => false,
            'active_operation' => [
                'operation' => 'plan',
                'status' => 'running',
                'progress_percent' => 45,
                'updated_at' => '2026-04-21 12:20:00',
            ],
            'plan_queue_info' => [
                'queue_id' => 143,
                'snapshot' => [
                    'queue_id' => 143,
                    'status' => 'running',
                    'job_status' => 'running',
                    'job_key' => 'glr_aisite:session:987:job:stage1.requirement_expand',
                    'job_type' => 'stage1.requirement_expand',
                    'token' => 'token-abc',
                    'token_usage' => [
                        'input_tokens' => 1200,
                        'output_tokens' => 340,
                        'total_tokens' => 1540,
                    ],
                    'start_at' => '2026-04-21 12:19:50',
                ],
                'process' => 'AI queue running',
                'result_log' => '',
            ],
            'task_plan_queue_info' => null,
            'build_queue_info' => null,
            'build_task_summary' => ['total' => 4, 'completed' => 1],
            'build_summary' => [],
            'pending_generation_page_types' => [],
            'events' => [
                ['event_id' => 41, 'event_type' => 'progress'],
            ],
            'top_logs' => [],
            'scope' => [
                'plan_confirmed' => 1,
                'execution_blueprint_confirmed_signature' => 'confirmed-sig',
            ],
        ];

        $pollingPayload = $pollingMethod->invoke($controller, $state);
        $ssePayload = $sseMethod->invoke($controller, $state, [], true);

        foreach ([
            'job_key',
            'job_type',
            'status',
            'event_id',
            'seq_no',
            'cursor',
            'progress_percent',
            'session_public_id',
            'context_hash',
            'state_fingerprint',
            'token_usage',
            'progress_kind',
            'updated_at',
        ] as $contractKey) {
            self::assertArrayHasKey($contractKey, $pollingPayload);
            self::assertArrayHasKey($contractKey, $ssePayload);
            self::assertSame($pollingPayload[$contractKey], $ssePayload[$contractKey], $contractKey);
        }

        self::assertSame('poller', $pollingPayload['source']);
        self::assertSame('queue', $ssePayload['source']);
        self::assertSame('queue_info', $pollingPayload['progress_kind']);
        self::assertSame(1200, $pollingPayload['token_usage']['input_tokens']);
        self::assertSame(340, $pollingPayload['token_usage']['output_tokens']);
        self::assertSame(1540, $pollingPayload['token_usage']['total_tokens']);
        self::assertSame(0, $ssePayload['plan_confirmed']);
        self::assertSame('', $ssePayload['plan_confirmed_at']);
        self::assertTrue($ssePayload['has_execution_blueprint']);
        self::assertSame(0, $ssePayload['task_plan_confirmed']);
        self::assertSame('', $ssePayload['task_plan_confirmed_at']);
        self::assertFalse($ssePayload['has_virtual_theme_plan']);
    }

    public function testTaskPlanRecoveryNoticeOverridesSettledDoneQueue(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildWorkspaceEntryQueueNotice');
        $method->setAccessible(true);

        $notice = $method->invoke($controller, [
            'operation' => 'task_plan',
            'status' => 'done',
            'message' => '第二阶段任务方案队列已完成。',
            'task_plan_recovery_action' => 'reused_queue',
        ], [
            'task_plan' => [
                'queue_id' => 82,
                'snapshot' => [
                    'queue_id' => 82,
                    'status' => 'done',
                    'name' => 'PageBuilder task_plan #82',
                    'biz_key' => 'glr_aisite:session:48:queue_slot:planning',
                ],
                'process' => '第二阶段任务方案已生成，正在输出完成标记',
                'result_log' => '',
            ],
        ]);

        self::assertTrue((bool)($notice['show'] ?? false));
        self::assertSame('warning', (string)($notice['level'] ?? ''));
        self::assertStringContainsString('已重跑', (string)($notice['message'] ?? ''));
        self::assertStringNotContainsString('已完成', (string)($notice['message'] ?? ''));
        self::assertSame('已重跑', (string)($notice['queue_status_label'] ?? ''));
    }

}
