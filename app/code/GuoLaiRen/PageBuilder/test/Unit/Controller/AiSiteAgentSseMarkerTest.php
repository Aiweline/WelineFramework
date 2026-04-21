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
            'biz_key' => 'glr_aisite:session:987:stage:plan:operation:plan',
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
        self::assertSame("QUEUE 开始执行\nLOG AI 生成中\n", $payload['result_log']);
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
                'biz_key' => 'glr_aisite:session:987:stage:plan:operation:plan',
                'status' => 'running',
                'pid' => 48104,
                'type_id' => 6,
                'finished' => 0,
                'process' => 'AI 流式生成中... (+33 B)',
                'result' => "QUEUE 开始执行\nLOG AI 生成中\n",
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
        self::assertArrayHasKey('queue_info', $infoEvents[0]['data']);
        self::assertSame('running', $infoEvents[0]['data']['queue_info']['snapshot']['status']);
        $panelUpdateEvents = \array_values(\array_filter(
            $infoEvents,
            static fn(array $event): bool => \is_array($event['data']) && !empty($event['data']['queue_panel_update'])
        ));
        self::assertNotEmpty($panelUpdateEvents);
        self::assertSame('AI 流式生成中... (+33 B)', $panelUpdateEvents[0]['data']['queue_process']);

        self::assertNotEmpty($chunkEvents);
        self::assertIsArray($chunkEvents[0]['data']);
        self::assertSame('QUEUE 开始执行' . PHP_EOL, $chunkEvents[0]['data']['queue_result_delta']);
        self::assertSame('AI 流式生成中... (+33 B)', $chunkEvents[0]['data']['queue_process']);
        self::assertSame(143, $chunkEvents[0]['data']['queue_snapshot']['queue_id']);
    }

    public function testOperationSseClaimedDispatcherIncludesPlanBranch(): void
    {
        $controllerSource = \file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Backend/AiSiteAgent.php'
        );

        self::assertIsString($controllerSource);
        self::assertStringContainsString(
            "'plan' => $this->runPlanOperationSseBranch",
            $controllerSource,
            'operation-sse claimed-operation dispatcher must route operation=plan instead of falling through to unknown operation.'
        );
        self::assertStringContainsString('private function runPlanOperationSseBranch', $controllerSource);
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

    public function testStageTwoTaskProgressUiSupportsRealtimeSemanticStatuses(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $runtimeScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');

        self::assertIsString($layout);
        self::assertIsString($runtimeScript);

        foreach (['todo', 'queued', 'running', 'done', 'failed', 'stale', 'cancelled'] as $status) {
            self::assertStringContainsString("__('{$status}')", $layout);
            self::assertStringContainsString("'{$status}'", $runtimeScript);
        }

        self::assertStringContainsString('data-task-progress-summary="stage2"', $layout);
        self::assertStringContainsString('pb-ai-task-queued', $layout);
        self::assertStringContainsString('pb-ai-task-failed', $layout);
        self::assertStringContainsString('pb-ai-task-stale', $layout);
        self::assertStringContainsString('pb-ai-task-cancelled', $layout);
        self::assertStringContainsString('TASK_PROGRESS_STATUSES', $runtimeScript);
        self::assertStringContainsString('normalizeTaskProgressStatus', $runtimeScript);
        self::assertStringContainsString("String(workspaceState.progress_kind || '') === 'task_progress'", $runtimeScript);
        self::assertStringContainsString('updateTaskSummaryFromState(payload)', $runtimeScript);
        self::assertStringContainsString('renderTaskStatusCountBadges(group)', $runtimeScript);
    }

}
