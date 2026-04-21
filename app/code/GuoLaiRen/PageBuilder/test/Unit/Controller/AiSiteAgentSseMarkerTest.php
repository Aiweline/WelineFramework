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
        ]);

        self::assertSame(143, $payload['queue_id']);
        self::assertSame('running', $payload['snapshot']['status']);
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

    public function testPhaseOneQueuePanelIsSubscribedToOperationSsePayloads(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $phaseScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-phase1-task-progress.phtml');
        $runtimeScript = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');

        self::assertIsString($phaseScript);
        self::assertIsString($runtimeScript);
        self::assertStringContainsString('syncFromSsePayload', $phaseScript);
        self::assertStringContainsString('mergePlanQueueInfoFromSsePayload', $phaseScript);
        self::assertStringContainsString('queue_result_delta', $phaseScript);
        self::assertStringContainsString('__pbPhase1TaskProgress.syncFromSsePayload(operation, payload || {}, eventKind)', $runtimeScript);
    }
}
