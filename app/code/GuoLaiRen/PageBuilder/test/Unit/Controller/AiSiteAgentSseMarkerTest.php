<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
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

    public function testRemovedTaskPlanGenerationSourceGateIsDeleted(): void
    {
        self::assertFalse((new ReflectionClass(AiSiteAgent::class))->hasMethod('shouldRejectTaskPlanGenerationSource'));
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

        self::assertTrue((bool)$isActive->invoke($controller, ['active_operation' => ['queue_status' => 'queued']]));
        self::assertTrue((bool)$isActive->invoke($controller, ['active_operation' => ['queue_status' => 'running']]));
        self::assertFalse((bool)$isActive->invoke($controller, ['active_operation' => ['queue_status' => 'done']]));

        foreach (['done', 'error', 'cancelled', 'stop', 'stopped'] as $status) {
            self::assertTrue(
                (bool)$isTerminal->invoke($controller, ['active_operation' => ['queue_status' => $status]]),
                $status . ' must close the workspace SSE stream once an active operation reaches a terminal state.'
            );
        }
        self::assertFalse((bool)$isTerminal->invoke($controller, ['active_operation' => ['queue_status' => 'running']]));

        $donePayload = $buildPayload->invoke($controller, [
            'public_id' => 'pb-terminal-test',
            'active_operation' => ['queue_status' => 'done', 'message' => 'plan done'],
        ], 42);
        self::assertSame('done', $donePayload['terminal_status']);
        self::assertTrue((bool)$donePayload['success']);
        self::assertSame('plan done', $donePayload['message']);
        self::assertSame(42, $donePayload['last_event_id']);

        $errorPayload = $buildPayload->invoke($controller, [
            'public_id' => 'pb-terminal-test',
            'active_operation' => ['queue_status' => 'error'],
        ], 43);
        self::assertSame('error', $errorPayload['terminal_status']);
        self::assertFalse((bool)$errorPayload['success']);
        self::assertSame(43, $errorPayload['last_event_id']);

        $stopPayload = $buildPayload->invoke($controller, [
            'public_id' => 'pb-terminal-test',
            'active_operation' => ['queue_status' => 'stop', 'message' => 'queue skipped'],
        ], 44);
        self::assertSame('stop', $stopPayload['terminal_status']);
        self::assertFalse((bool)$stopPayload['success']);
        self::assertSame('queue skipped', $stopPayload['message']);
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
            'process' => 'AI plan queue failed after retries.',
        ];

        self::assertTrue((bool)$isTerminal->invoke($controller, $queueRow));
        self::assertFalse((bool)$isInProgress->invoke($controller, $queueRow));
    }

    public function testObservedQueueRunningStatusIsTrustedWithoutRequestPidProbe(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $isInProgress = new ReflectionMethod(AiSiteAgent::class, 'isObservedQueueInProgress');
        $isInProgress->setAccessible(true);

        self::assertTrue((bool)$isInProgress->invoke($controller, [
            'status' => 'running',
            'pid' => 0,
            'finished' => 0,
            'process' => 'worker status is authoritative',
        ]));
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

    public function testRuntimeDoesNotPollRemovedTaskPlanQueue(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml'
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('task_plan_queue_skip', $source);
        self::assertStringNotContainsString('startDeferredQueueStatePoll(\'task_plan\')', $source);
        self::assertStringNotContainsString('taskPlanSkip', $source);
        self::assertStringContainsString('isPlanningQueueSoftTerminalStop', $source);
        self::assertStringContainsString('toast(\'warning\'', $source);
        self::assertStringNotContainsString('syncTaskPlanSseRunningFromWorkspaceState', $source);
    }

    public function testPlanQueueProgressKeepsStructuredCountsWhenListsAreCompacted(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml'
        );

        self::assertIsString($source);
        $methodOffset = \strpos($source, 'function normalizePlanQueuePageProgress');
        self::assertNotFalse($methodOffset);
        $methodSource = \substr($source, $methodOffset, 2400);

        foreach ([
            'var doneCount = parsePlanQueueNonNegativeCount(source.done_count, done.length);',
            'var runningCount = parsePlanQueueNonNegativeCount(source.running_count, running.length);',
            'var failedCount = parsePlanQueueNonNegativeCount(source.failed_count, failed.length);',
            'var pendingCount = parsePlanQueueNonNegativeCount(source.pending_count, pending.length);',
            'remaining_count: resolvePlanQueueRemainingCount(source, total, doneCount, runningCount, failedCount, pendingCount)',
        ] as $needle) {
            self::assertStringContainsString($needle, $methodSource);
        }
        self::assertStringContainsString(
            'var pendingCount = parseInt(String(normalized.pending_count != null ? normalized.pending_count : pending.length), 10) || 0;',
            $source
        );
        self::assertStringContainsString(
            "renderPlanQueueProgressBar('Pending', pendingCount, total, 'secondary', pending)",
            $source
        );
        self::assertStringContainsString(
            'function resolvePlanQueueRemainingCount(source, total, doneCount, runningCount, failedCount, pendingCount)',
            $source
        );
        self::assertStringNotContainsString(
            "renderPlanQueueProgressBar('Pending', pending.length, total, 'secondary', pending)",
            $source
        );
        self::assertStringContainsString(
            'var blockTotal = Math.max(blocks.length, parseInt(String(row.block_total || 0), 10) || 0);',
            $source
        );
        self::assertStringContainsString(
            'row.block_done_count != null ? row.block_done_count : counts.done',
            $source
        );
        self::assertStringContainsString(
            'var hiddenBlockCount = Math.max(0, blockTotal - Math.min(blocks.length, 24));',
            $source
        );
        self::assertStringContainsString(
            "var active = ['pending', 'queued', 'running', 'processing', 'retrying'].indexOf(status) >= 0;",
            $source
        );
        self::assertStringContainsString(
            'var candidates = active',
            $source
        );
        self::assertMatchesRegularExpression(
            '/queueInfo\\.process,\\s+queueInfo\\.message,\\s+queueInfo\\.latest_message/s',
            $source
        );
    }

    public function testStageOnePlanJsonMergeKeepsNumericPageFanoutByPageType(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'mergeStageOnePersistedPlanJson');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $controller,
            [
                'pages' => [
                    'home_page' => [
                        'page_type' => 'home_page',
                        'hero' => ['block_key' => 'hero', 'content' => 'Home hero'],
                    ],
                ],
            ],
            [
                'pages' => [
                    'about_page' => [
                        'page_type' => 'about_page',
                        'intro' => ['block_key' => 'intro', 'content' => 'About intro'],
                    ],
                ],
            ]
        );

        self::assertArrayHasKey('home_page', $merged['pages']);
        self::assertArrayHasKey('about_page', $merged['pages']);
        self::assertArrayNotHasKey(0, $merged['pages']);
        self::assertSame('Home hero', $merged['pages']['home_page']['hero']['content']);
        self::assertSame('About intro', $merged['pages']['about_page']['intro']['content']);
    }

    public function testStageOnePlanJsonMergeKeepsIncrementalPagesByPageType(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'mergeStageOnePersistedPlanJson');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $controller,
            [
                'pages' => [
                    [
                        'page_type' => 'home_page',
                        'hero' => ['summary' => 'Home hero'],
                    ],
                ],
            ],
            [
                'pages' => [
                    [
                        'type' => 'blog_list',
                        'post_grid' => ['summary' => 'Blog grid'],
                    ],
                ],
            ]
        );

        self::assertArrayHasKey('home_page', $merged['pages']);
        self::assertArrayHasKey('blog_list', $merged['pages']);
        self::assertArrayNotHasKey(0, $merged['pages']);
        self::assertSame('Home hero', $merged['pages']['home_page']['hero']['summary']);
        self::assertSame('Blog grid', $merged['pages']['blog_list']['post_grid']['summary']);
    }

    public function testBlockConfigReplacementOnlyTouchesSelectedPageBlock(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'replaceCurrentPageBlockInVirtualPages');
        $method->setAccessible(true);

        $virtualPages = [
            'home' => [
                'site_header' => [
                    'block_id' => 'home-site-header',
                    'type' => 'site_header',
                    'html' => '<header>Home before</header>',
                    'config' => ['site_title' => 'Home before'],
                ],
                'hero' => [
                    'block_id' => 'home-hero',
                    'type' => 'hero',
                    'html' => '<section>Hero before</section>',
                    'config' => ['headline' => 'Hero before'],
                ],
                'blocks' => [
                    ['block_id' => 'legacy-home-site-header', 'config' => ['site_title' => 'Legacy before']],
                ],
            ],
            'about' => [
                'site_header' => [
                    'block_id' => 'about-site-header',
                    'type' => 'site_header',
                    'html' => '<header>About before</header>',
                    'config' => ['site_title' => 'About before'],
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

        self::assertSame('Home tuned', $result['home']['site_header']['config']['site_title']);
        self::assertSame('<header>Home tuned</header>', $result['home']['site_header']['html']);
        self::assertSame('Hero before', $result['home']['hero']['config']['headline']);
        self::assertSame('Legacy before', $result['home']['blocks'][0]['config']['site_title']);
        self::assertSame('About before', $result['about']['site_header']['config']['site_title']);
        self::assertSame('<header>About before</header>', $result['about']['site_header']['html']);
        self::assertSame('2026-04-21 15:20:00', $result['home']['last_generated_at']);
        self::assertArrayNotHasKey('last_generated_at', $result['about']);
    }

    public function testExistingAiHtmlBlocksPreferCanonicalPlanJsonBlockNodes(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'resolveExistingAiHtmlBlocksForPage');
        $method->setAccessible(true);

        $scope = [
            'plan_json' => [
                'pages' => [
                    'home' => [
                        'page_type' => 'home',
                        'hero' => [
                            'block_id' => 'home-hero',
                            'status' => 1,
                            'html' => '<section>Canonical hero</section>',
                        ],
                    ],
                ],
            ],
        ];
        $virtualPages = [
            'home' => [
                'blocks' => [
                    ['block_id' => 'home-hero', 'html' => ''],
                ],
            ],
        ];

        $blocks = $method->invoke($controller, 'home', $scope, $virtualPages);

        self::assertSame('<section>Canonical hero</section>', $blocks[0]['html'] ?? '');
        self::assertSame('hero', $blocks[0]['block_key'] ?? '');
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
            'pid' => \getmypid(),
            'type_id' => 6,
            'finished' => 0,
            'start_at' => '2026-04-21 02:15:53',
            'end_at' => '2026-04-21 02:16:04',
            'process' => 'AI 濠电姷鏁告慨鐑藉极閹间礁纾婚柣鏃傚劋瀹曞弶绻濋棃娑氬ⅱ闁活厼妫楅湁闁挎繂娲ら崝瀣亜椤愶絾绀嬮柡灞诲姂閹垽宕崟鎴秮閺屾稓鈧絻鍔屾慨鍌炴煛瀹€瀣М鐎殿噮鍣ｅ畷鎺楀Χ閸パ冃梻鍌欐祰椤曆呮崲閹邦優娲Χ婢跺﹦鍙€?.. (+33 B)',
            'result' => "[02:16:04] INFO state saved\n",
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
        self::assertArrayNotHasKey('snapshot', $payload);
        self::assertSame('running', $payload['status']);
        self::assertSame('glr_aisite:session:987:job:stage1.requirement_expand', $payload['job_key']);
        self::assertSame('stage1.requirement_expand', $payload['job_type']);
        self::assertSame('token-abc', $payload['token']);
        self::assertSame(1200, $payload['token_usage']['input_tokens']);
        self::assertSame(340, $payload['token_usage']['output_tokens']);
        self::assertSame(1540, $payload['token_usage']['total_tokens']);
        self::assertSame('AI 濠电姷鏁告慨鐑藉极閹间礁纾婚柣鏃傚劋瀹曞弶绻濋棃娑氬ⅱ闁活厼妫楅湁闁挎繂娲ら崝瀣亜椤愶絾绀嬮柡灞诲姂閹垽宕崟鎴秮閺屾稓鈧絻鍔屾慨鍌炴煛瀹€瀣М鐎殿噮鍣ｅ畷鎺楀Χ閸パ冃梻鍌欐祰椤曆呮崲閹邦優娲Χ婢跺﹦鍙€?.. (+33 B)', $payload['process']);
        self::assertSame("[02:16:04] INFO state saved", $payload['result_log']);
    }

    public function testQueueObserverPanelPayloadPreservesRunningQueueForSchedulerRecovery(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildQueueObserverPanelPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, [
            'queue_id' => 144,
            'name' => 'PageBuilder plan',
            'module' => 'GuoLaiRen_PageBuilder',
            'biz_key' => 'glr_aisite:session:987:queue_slot:plan',
            'status' => 'running',
            'pid' => 999999,
            'type_id' => 6,
            'finished' => 0,
            'process' => 'prior running message',
        ]);

        self::assertSame('running', $payload['status']);
        self::assertSame('running', $payload['queue_status']);
        self::assertArrayNotHasKey('job_status', $payload);
        self::assertArrayNotHasKey('retry_allowed', $payload);
        self::assertArrayNotHasKey('queue_terminal_recovered', $payload);
        self::assertSame('prior running message', $payload['process']);
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
        self::assertSame('queued', $envelope['queue_status']);
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
                'process' => 'AI stream running... (+33 B)',
                'result' => "[02:16:04] INFO state saved\n",
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
        $queueInfoEvents = \array_values(\array_filter(
            $writer->events,
            static fn(array $event): bool => \is_array($event['data'] ?? null)
                && ($event['data']['progress_kind'] ?? '') === 'queue_info'
                && \is_array($event['data']['queue_info'] ?? null)
        ));

        self::assertNotEmpty($infoEvents);
        self::assertIsArray($infoEvents[0]['data']);
        self::assertSame('queue_info', $infoEvents[0]['data']['progress_kind']);
        self::assertArrayHasKey('queue_info', $infoEvents[0]['data']);
        self::assertArrayNotHasKey('snapshot', $infoEvents[0]['data']['queue_info']);
        self::assertSame('running', $infoEvents[0]['data']['queue_info']['queue_status']);
        self::assertSame(600, $infoEvents[0]['data']['token_usage']['input_tokens']);
        self::assertSame(180, $infoEvents[0]['data']['queue_info']['token_usage']['output_tokens']);
        $processEvents = \array_values(\array_filter(
            $queueInfoEvents,
            static fn(array $event): bool => (string)($event['data']['queue_process'] ?? '') !== ''
        ));
        self::assertNotEmpty($processEvents);
        self::assertSame('AI stream running... (+33 B)', $processEvents[0]['data']['queue_process']);

        self::assertSame([], $chunkEvents, 'queue.result should not replay as chunk SSE');
        self::assertArrayNotHasKey('queue_result_delta', $processEvents[0]['data']);
        self::assertSame("[02:16:04] INFO state saved", (string)$processEvents[0]['data']['queue_info']['result_log']);
        self::assertSame(780, $processEvents[0]['data']['token_usage']['total_tokens']);
        self::assertSame(780, $processEvents[0]['data']['queue_info']['token_usage']['total_tokens']);
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
        self::assertStringContainsString('observer_mode', $controllerSource);
    }

    public function testPhaseOneQueuePanelIsSubscribedToOperationSsePayloads(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $phaseScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        $runtimeScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($phaseScript);
        self::assertIsString($runtimeScript);
        self::assertIsString($controllerSource);
        self::assertStringContainsString('syncFromSsePayload', $phaseScript);
        self::assertStringContainsString('mergePlanQueueInfoFromSsePayload', $phaseScript);
        self::assertStringContainsString('renderQueueInfoSummary', $phaseScript);
        self::assertStringContainsString('resolveTokenUsage', $phaseScript);
        self::assertStringContainsString('data-queue-info-list="stage1"', $phaseScript);
        self::assertStringContainsString("data-queue-info-field=\"' + escapeHtml(key) + '\"", $phaseScript);
        self::assertStringContainsString('data-token-usage-field', $phaseScript);
        self::assertStringContainsString("'queue_status'", $phaseScript);
        self::assertStringContainsString("var status = (info && info.queue_status) || '';", $phaseScript);
        self::assertStringContainsString("var status = String(queueInfo.queue_status || '').toLowerCase();", $phaseScript);
        self::assertStringContainsString("statusEl.textContent = String(info.queue_status || '').trim();", $phaseScript);
        self::assertStringNotContainsString("info.job_status || info.status || info.queue_status", $phaseScript);
        self::assertStringNotContainsString("queueInfo.semantic_status || queueInfo.job_status || queueInfo.queue_status || queueInfo.status", $phaseScript);
        self::assertStringNotContainsString("payload.job_status || payload.status", $phaseScript);
        self::assertStringNotContainsString("Object.prototype.hasOwnProperty.call(payload, 'job_status')", $phaseScript);
        self::assertStringNotContainsString("Object.prototype.hasOwnProperty.call(payload, 'status')", $phaseScript);
        self::assertStringContainsString('payload.progress_kind', $phaseScript);
        self::assertStringContainsString('input_tokens', $phaseScript);
        self::assertStringContainsString('output_tokens', $phaseScript);
        self::assertStringContainsString('total_tokens', $phaseScript);
        self::assertStringContainsString('prompt_tokens', $phaseScript);
        self::assertStringContainsString('completion_tokens', $phaseScript);
        self::assertStringContainsString('function appendLiveQueueLog(delta)', $phaseScript);
        self::assertStringContainsString('resEl.appendChild(node)', $phaseScript);
        self::assertStringContainsString('while (resEl.childNodes.length > LIVE_LOG_MAX_LINES)', $phaseScript);
        self::assertStringContainsString('safeState.queue_status.plan = mergeIncomingPlanQueueInfoWithRememberedProgress(planQueueInfo);', $phaseScript);
        self::assertStringNotContainsString('state.plan_queue_info = mergeIncomingPlanQueueInfoWithRememberedProgress(info);', $phaseScript);
        self::assertStringContainsString('function hasNonEmptyStageOneProgress(progress)', $phaseScript);
        self::assertStringContainsString('stage1_page_progress', $phaseScript);
        self::assertStringContainsString('function resolvePlanQueueLatestPageProgress(info)', $phaseScript);
        self::assertStringContainsString('function mergeIncomingPlanQueueInfoWithRememberedProgress(info)', $phaseScript);
        self::assertStringContainsString('$includeQueuePayload = $operation === \'plan\';', $controllerSource);
        self::assertStringContainsString('findAiSiteOperationQueueRow($fresh, $operation, $queueId, $includeQueuePayload)', $controllerSource);
        self::assertStringContainsString('$queueId = $effectiveQueueId > 0 ? $effectiveQueueId : $queueId;', $controllerSource);
        self::assertStringContainsString('findAiSiteOperationQueueRow($fresh, $operation, $queueId, $operation === \'plan\')', $controllerSource);
        self::assertStringContainsString('window.__pbWorkspaceApi.updateStageStatusSummary(state)', $phaseScript);
        self::assertStringContainsString('window.__pbWorkspaceApi.updateStageStatusSummary(safeState)', $phaseScript);
        self::assertStringContainsString("activeOp === 'plan' && isStageQueueInProgress(readStageQueueStatus(resolveRuntimeQueueInfoFromState(state, 'plan')))", $runtimeScript);
        self::assertStringContainsString("var directStatus = String(candidate.queue_status || '').trim();", $runtimeScript);
        self::assertStringContainsString('queueInfo.queue_status', $runtimeScript);
        self::assertStringContainsString("task.status = normalizeTaskProgressStatus(payload.status);", $runtimeScript);
        self::assertStringNotContainsString('queueInfo.status', $runtimeScript);
        self::assertStringNotContainsString('queueInfo.state', $runtimeScript);
        self::assertStringNotContainsString('queueInfo.job_status', $runtimeScript);
        self::assertStringNotContainsString('queueState.status', $runtimeScript);
        self::assertStringNotContainsString('queueState.job_status', $runtimeScript);
        self::assertStringNotContainsString('candidate.queue_status || candidate.status', $runtimeScript);
        self::assertStringNotContainsString('payload.job_status || payload.status', $runtimeScript);
        self::assertStringContainsString("'result_log', 'queue_result_delta', 'chunk', 'content', 'events', 'top_logs'", $phaseScript);
        self::assertStringContainsString("'events', 'top_logs', 'result_log', 'queue_result_delta', 'chunk', 'content'", $phaseScript);
        self::assertStringNotContainsString('state.queue_result_delta =', $phaseScript);
        self::assertStringNotContainsString('state.plan_queue_info = info;', $phaseScript);
        self::assertStringContainsString('__pbPhase1TaskProgress.syncFromSsePayload(operation, payload || {}, eventKind)', $runtimeScript);
    }

    public function testWorkspaceStreamingUiUsesBoundedDomBuffersInsteadOfAccumulatingLogs(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $appCodeRoot = \dirname(__DIR__, 5);
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $runtimeScript = (string)\file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $buildScript = (string)\file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');
        $phaseScript = (string)\file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        $guidedStyles = (string)\file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/styles-guided.phtml');
        $terminalSource = (string)\file_get_contents($appCodeRoot . '/Weline/Theme/Taglib/SseTerminal.php');

        self::assertStringContainsString('function appendPlanStreamChunk(chunk, payload)', $mainScript);
        self::assertStringContainsString('var PLAN_STREAM_PREVIEW_MAX_CHARS = 60000;', $mainScript);
        self::assertStringContainsString('var PLAN_STREAM_PREVIEW_MAX_LINES = 600;', $mainScript);
        self::assertStringContainsString('function appendBoundedPlanStreamMarkdown(current, piece)', $mainScript);
        self::assertStringContainsString('currentPlanStreamMarkdown = appendBoundedPlanStreamMarkdown(currentPlanStreamMarkdown, piece);', $mainScript);
        self::assertStringContainsString('currentPlanPayload.markdown_stream_preview = true;', $mainScript);
        self::assertStringContainsString('function releasePlanStreamBuffer(options)', $mainScript);
        self::assertStringContainsString('releasePlanStreamBuffer({ clearPreviewPayload: true });', $mainScript);
        self::assertStringNotContainsString('currentPlanStreamMarkdown = String(currentPlanStreamMarkdown || \'\') + piece;', $mainScript);
        self::assertStringContainsString('requestAnimationFrame(function ()', $mainScript);
        self::assertStringNotContainsString('textContent + chunk', $mainScript);
        self::assertStringContainsString('var INLINE_STREAM_MAX_CHARS = 24000;', $mainScript);
        self::assertStringContainsString('var INLINE_STREAM_MAX_TEXT_LINES = 200;', $mainScript);
        self::assertStringContainsString('function appendSseParserBuffer(current, piece)', $mainScript);
        self::assertStringContainsString('INLINE_STREAM_EVENT_BUFFER_MAX_CHARS', $mainScript);
        self::assertStringContainsString('function abortComponentConfigModalStream(modalElement)', $mainScript);
        self::assertStringContainsString('__pbComponentConfigStreamReader.cancel()', $mainScript);
        self::assertStringContainsString('function abortBlockEditorAiStream()', $mainScript);
        self::assertStringContainsString('abortCurrentStream();', $mainScript);
        self::assertStringContainsString('clearStreamRefs();', $mainScript);
        self::assertStringContainsString("state.queue_status[key] = sanitizeQueueInfoForMemory(state.queue_status[key]);", $phaseScript);
        self::assertStringContainsString("state.queue_status[key] = sanitizeQueueInfoForMemory(state.queue_status[key]);", $buildScript);
        self::assertStringNotContainsString("safe.scope[key] = sanitizeQueueInfoForMemory(safe.scope[key]);", $mainScript);
        self::assertStringNotContainsString("['page_type_layouts', 'pagebuilder_pages_by_type', 'virtual_pages_by_type']", $mainScript);
        self::assertStringContainsString('function sanitizeWorkspaceStateCollectionForMemory(collection)', $mainScript);

        self::assertStringContainsString('writeChunk: function(text)', $terminalSource);
        self::assertStringContainsString('function pruneTerminalLines()', $terminalSource);
        self::assertStringContainsString('while (content.children.length > maxDomLines)', $terminalSource);
        self::assertStringContainsString('max-dom-lines', $terminalSource);
        self::assertStringContainsString('workspaceTerminal.writeChunk(message);', $runtimeScript);
        self::assertStringContainsString('function disposeOperationLiveStream(operation, summary)', $runtimeScript);
        self::assertStringContainsString('window.__pbPhase1TaskProgress.disposeLiveStream(finalSummary);', $runtimeScript);
        self::assertStringContainsString('window.__pbBuildQueueProgress.disposeLiveStream(normalized || \'build\', finalSummary);', $runtimeScript);
        self::assertStringContainsString('disposeOperationLiveStream(targetOperation, \'\');', $runtimeScript);
        self::assertStringContainsString('function compactOperationFailurePayload(operation, payload, fallbackMessage)', $runtimeScript);
        self::assertStringContainsString('lastFailurePayloadByOperation[op] = nextPayload;', $runtimeScript);
        self::assertStringNotContainsString('lastFailurePayloadByOperation[op] = Object.assign({}, payload);', $runtimeScript);
        self::assertStringContainsString('@media (max-width: 575.98px)', $guidedStyles);
        self::assertStringContainsString('.pb-guided-steps {', $guidedStyles);
        self::assertStringContainsString('flex-direction: column;', $guidedStyles);
        self::assertStringContainsString('.pb-guided-step .step-label', $guidedStyles);
        self::assertStringContainsString('white-space: normal;', $guidedStyles);

        foreach ([$buildScript, $phaseScript] as $script) {
            self::assertStringContainsString('var LIVE_LOG_MAX_LINES = 200;', $script);
            self::assertStringContainsString('while (resEl.childNodes.length > LIVE_LOG_MAX_LINES)', $script);
            self::assertStringContainsString('function disposeLiveStream', $script);
            self::assertTrue(
                \str_contains($script, "disposeLiveStream('');")
                || \str_contains($script, "disposeLiveStream(key, '');"),
                'details close should clear the bounded live log DOM'
            );
            self::assertStringContainsString('if (panelEl && !panelEl.open)', $script);
            self::assertStringContainsString('state.queue_status[key] = sanitizeQueueInfoForMemory(state.queue_status[key]);', $script);
            self::assertStringNotContainsString('state.scope[key] = sanitizeQueueInfoForMemory(state.scope[key]);', $script);
            self::assertStringContainsString("'result_log', 'queue_result_delta', 'chunk', 'content', 'events', 'top_logs'", $script);
            self::assertStringContainsString("'events', 'top_logs', 'result_log', 'queue_result_delta', 'chunk', 'content'", $script);
        }
    }

    public function testRuntimeStagePresentationLetsDoneQueueOverrideStaleActiveFailure(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $scriptPaths = [
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml',
        ];

        foreach ($scriptPaths as $scriptPath) {
            $runtimeScript = \file_get_contents($scriptPath);

            self::assertIsString($runtimeScript);
            self::assertStringContainsString("['done', 'complete', 'completed']", $runtimeScript);

            $planDonePos = \strpos($runtimeScript, "if (isStageQueueDone(planQueueStatus) && activeOp === 'plan')");
            $planErrorPos = \strpos($runtimeScript, "if (activeOp === 'plan' && activeStatus === 'error')");
            $buildDonePos = \strpos($runtimeScript, 'if (isStageQueueDone(buildQueueStatus))');
            $buildErrorPos = \strpos($runtimeScript, "if (activeOp === 'build' && activeStatus === 'error')");

            self::assertNotFalse($planDonePos, $scriptPath);
            self::assertNotFalse($planErrorPos, $scriptPath);
            self::assertNotFalse($buildDonePos, $scriptPath);
            self::assertNotFalse($buildErrorPos, $scriptPath);

            self::assertLessThan($planErrorPos, $planDonePos, $scriptPath);
            self::assertLessThan($buildErrorPos, $buildDonePos, $scriptPath);
            self::assertStringNotContainsString("activeOp === 'task_plan'", $runtimeScript);
        }
    }

    public function testPageTypeSelectionDoesNotLetDefaultServerStateOverwriteLocalCustomChoice(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($mainScript);
        self::assertIsString($controllerSource);
        self::assertStringContainsString('var pageTypesSelectionLocallyCustomized = !!pageTypesUserCustomized;', $mainScript);
        self::assertStringContainsString('var skipServerOverwrite = pageTypesSelectionLocallyCustomized && !incomingMatchesCurrentSelection;', $mainScript);
        self::assertStringContainsString("page_types_user_customized: (pageTypesUserCustomized || pageTypesSelectionLocallyCustomized) ? 1 : 0", $mainScript);
        self::assertStringContainsString('serverPageTypesFallback = normalizedTypes.slice();', $mainScript);
        self::assertStringContainsString('$scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;', $controllerSource);
        $oldDraftVar = '$planJsonGeneration' . 'Dr' . 'aft';
        self::assertStringNotContainsString("\$scopePatch['page_types'] = " . $oldDraftVar . "['page_types'];", $controllerSource);
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
        $phaseTwoScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');

        self::assertIsString($phaseOneScript);
        self::assertIsString($phaseTwoScript);

        foreach ([$phaseOneScript, $phaseTwoScript] as $script) {
            self::assertStringContainsString('data-token-usage-field', $script);
            self::assertStringContainsString("renderQueueSummaryItem('input_tokens'", $script);
            self::assertStringContainsString("renderQueueSummaryItem('output_tokens'", $script);
            self::assertStringContainsString("renderQueueSummaryItem('total_tokens'", $script);
        }

        self::assertStringContainsString('pb-ai-plan-queue-token-summary', $phaseOneScript);
        self::assertStringContainsString('pb-ai-build-queue-token-summary', $phaseTwoScript);
        self::assertStringContainsString('pickTokenCount(usage, [\'input_tokens\', \'prompt_tokens\'])', $phaseTwoScript);
    }

    public function testBuildQueuePanelReadsOnlyCanonicalQueueStatus(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $buildScript = (string)\file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');

        self::assertStringContainsString("var status = (info && info.queue_status) || '';", $buildScript);
        self::assertStringContainsString("statusEl.textContent = String(info.queue_status || '').trim();", $buildScript);
        self::assertStringContainsString("parsedPayload.queue_status\n            || (info && info.queue_status)", $buildScript);
        self::assertStringContainsString("var directStatus = String(candidate.queue_status || '').trim();", $buildScript);
        self::assertStringContainsString("'queue_status', 'job_key', 'job_type', 'token', 'execution_token', 'input_tokens', 'output_tokens', 'total_tokens'", $buildScript);
        self::assertStringNotContainsString('delete queueState.status;', $buildScript);
        self::assertStringNotContainsString('delete queueState.job_status;', $buildScript);
        self::assertStringNotContainsString('info.status || info.queue_status || info.job_status', $buildScript);
        self::assertStringNotContainsString('info.job_status || info.status || info.queue_status', $buildScript);
        self::assertStringNotContainsString('parsedPayload.job_status', $buildScript);
        self::assertStringNotContainsString('parsedPayload.status', $buildScript);
        self::assertStringNotContainsString('candidate.queue_status || candidate.status', $buildScript);
        self::assertStringNotContainsString("Object.prototype.hasOwnProperty.call(payload, 'job_status')", $buildScript);
        self::assertStringNotContainsString("Object.prototype.hasOwnProperty.call(payload, 'status')", $buildScript);
        self::assertStringNotContainsString('queueState.queue_status || queueState.status', $buildScript);
        self::assertStringNotContainsString('queueState.job_status || queueState.status', $buildScript);
    }

    public function testPlanQueueDuplicateStreamRequiresPersistedStageOnePlan(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        $planQueueSource = \file_get_contents($moduleRoot . '/Queue/AiSitePlanQueue.php');

        self::assertIsString($controllerSource);
        self::assertIsString($planQueueSource);
        self::assertStringContainsString("string \$claimSource = 'direct_operation'", $controllerSource);
        self::assertStringContainsString("'reason' => 'queue_observer_only'", $controllerSource);
        self::assertStringContainsString("&& \$claimSource === 'queue'", $controllerSource);
        self::assertStringContainsString('&& !$this->scopeHasPersistedStageOnePlan($scope)', $controllerSource);
        self::assertStringContainsString("'claimed_by' => \$claimSource", $controllerSource);
        self::assertStringContainsString('[$session, $adminId, $effectiveExecutionToken, \'plan\', \'queue\']', $planQueueSource);
    }

    public function testPlanJsonTaskProgressUiSupportsRealtimeSemanticStatuses(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $mainScript = \GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader::loadBundledJavaScript();
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

        self::assertStringContainsString('data-task-progress-summary="build"', $layout);
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
        self::assertStringContainsString('function shouldConsumePlanJsonTaskProgressSummary(source)', $runtimeScript);
        self::assertStringContainsString("['build', 'regenerate_page', 'block_regenerate']", $runtimeScript);
        self::assertStringNotContainsString("activeOp === 'task_plan'", $runtimeScript);
        self::assertStringContainsString('updateTaskSummaryFromState(payload)', $runtimeScript);
        self::assertStringContainsString('renderTaskStatusCountBadges(group)', $runtimeScript);
        self::assertStringContainsString('renderTaskProgressGroupSummary(group)', $runtimeScript);
        self::assertStringContainsString('previewBridge = resolvePreviewBridge();', $mainScript);
        self::assertStringContainsString('window.PbAiWorkspacePreview.syncPreviewMetaFromState(payload.state)', $runtimeScript);
        self::assertStringContainsString('window.PbAiWorkspacePreview.switchPreviewByType(pageType);', $runtimeScript);
        self::assertStringContainsString('$groupSummaryLabel = (string)__(', $layout);
        self::assertStringContainsString('htmlspecialchars($groupSummaryLabel', $layout);
        self::assertStringContainsString('runtimeLabels.statusDone', $runtimeScript);
        self::assertStringContainsString('runtimeLabels.statusRunning', $runtimeScript);
        self::assertStringContainsString('runtimeLabels.statusPending', $runtimeScript);
        self::assertStringContainsString('if (doneEl) { doneEl.textContent = String(counts.done || 0); }', $runtimeScript);
        self::assertStringContainsString('private function emitObservedPlanJsonTaskProgressState', $controllerSource);
        self::assertStringContainsString('private function planJsonTaskProgressStatePayload', $controllerSource);
        self::assertStringContainsString("'progress_kind' => 'task_progress'", $controllerSource);
        self::assertStringContainsString('\'ai_generating\' => $aiGenerating', $controllerSource);
        self::assertStringContainsString('private function buildObservedProgressPayload', $controllerSource);
    }

    public function testWorkspacePollingPayloadUsesSameStatusEnvelopeAsSseState(): void
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
            'plan_json' => [
                'confirmed' => 0,
                'confirmed_at' => '',
                'pages' => [
                    'home_page' => [
                        'hero' => ['status' => 0],
                    ],
                ],
            ],
            'has_stage_one_plan' => true,
            'active_operation' => [
                'operation' => 'plan',
                'queue_status' => 'running',
                'progress_percent' => 45,
                'updated_at' => '2026-04-21 12:20:00',
            ],
            'plan_queue_info' => [
                'queue_id' => 143,
                'queue_status' => 'running',
                'job_key' => 'glr_aisite:session:987:job:stage1.requirement_expand',
                'job_type' => 'stage1.requirement_expand',
                'token' => 'token-abc',
                'token_usage' => [
                    'input_tokens' => 1200,
                    'output_tokens' => 340,
                    'total_tokens' => 1540,
                ],
                'start_at' => '2026-04-21 12:19:50',
                'process' => 'AI queue running',
                'result_log' => '',
            ],
            'build_queue_info' => null,
            'plan_json_task_summary' => ['total' => 4, 'completed' => 1],
            'build_summary' => [],
            'pending_generation_page_types' => [],
            'events' => [
                ['event_id' => 41, 'event_type' => 'progress'],
            ],
            'top_logs' => [],
            'scope' => [
                'plan_json' => [
                    'confirmed' => 0,
                    'pages' => [
                        'home_page' => [
                            'hero' => ['status' => 0],
                        ],
                    ],
                ],
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
        self::assertSame(0, (int)($ssePayload['plan_json']['confirmed'] ?? 1));
        self::assertSame('', (string)($ssePayload['plan_json']['confirmed_at'] ?? ''));
        self::assertTrue($ssePayload['has_stage_one_plan']);
    }

    public function testWorkspaceAndOperationSseTerminalPathsCloseStreamContract(): void
    {
        $moduleRoot = \defined('BP')
            ? BP . '/app/code/GuoLaiRen/PageBuilder'
            : \dirname(__DIR__, 4);
        $controllerSource = \file_get_contents($moduleRoot . '/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controllerSource);

        // Workspace stream terminal recognition must include done/error/cancelled.
        self::assertStringContainsString(
            "return \\in_array(\$status, ['done', 'error', 'cancelled'], true);",
            $controllerSource
        );
        // Workspace stream loop exits on terminal and sends complete payload (complete() implies close()).
        self::assertStringContainsString(
            '$sse->complete($terminalCompletePayload);',
            $controllerSource
        );
        self::assertStringContainsString("'success' => !\$streamObservedActiveOperation,", $controllerSource);
        self::assertStringContainsString("'terminal_status' => \$streamObservedActiveOperation ? 'incomplete' : '',", $controllerSource);
        self::assertStringNotContainsString(
            "\$sse->complete(['success' => true, 'last_event_id' => \$lastEventId]);",
            $controllerSource
        );

        // Operation SSE error branches still end with complete/close.
        self::assertStringContainsString(
            "\$sse->complete(['success' => false, 'message' => \$throwable->getMessage(), 'operation' => \$operation]);",
            $controllerSource
        );
    }

    public function testWorkspaceEntryNoticeReportsSettledBuildQueue(): void
    {
        $controller = (new ReflectionClass(AiSiteAgent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AiSiteAgent::class, 'buildWorkspaceEntryQueueNotice');
        $method->setAccessible(true);

        $notice = $method->invoke($controller, [
            'operation' => 'build',
            'queue_status' => 'done',
            'message' => 'Build queue completed.',
        ], [
            'build' => [
                'queue_id' => 82,
                'queue_status' => 'done',
                'name' => 'PageBuilder build #82',
                'biz_key' => 'glr_aisite:session:48:queue_slot:build',
                'process' => 'Build queue completed.',
                'result_log' => '',
            ],
        ]);

        self::assertTrue((bool)($notice['show'] ?? false));
        self::assertSame('success', (string)($notice['level'] ?? ''));
        self::assertStringContainsString('#82', (string)($notice['message'] ?? ''));
        self::assertNotSame('', (string)($notice['message'] ?? ''));
        self::assertSame('done', (string)($notice['queue_status'] ?? ''));
    }

}
