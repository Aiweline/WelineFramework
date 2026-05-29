<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Http\Sse {
    function w_query(string $provider, string $operation, array $params = []): mixed
    {
        return \GuoLaiRen\PageBuilder\Test\Unit\Http\Sse\QueueDbWriterWQuerySpy::handle(
            $provider,
            $operation,
            $params
        );
    }
}

namespace GuoLaiRen\PageBuilder\Test\Unit\Http\Sse {

use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QueueDbWriterWQuerySpy
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $rows = [];

    /**
     * @var list<array<string, mixed>>
     */
    public static array $updates = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function reset(array $rows = []): void
    {
        self::$rows = $rows;
        self::$updates = [];
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function handle(string $provider, string $operation, array $params = []): mixed
    {
        if ($provider !== 'queue') {
            return null;
        }

        $queueId = (int)($params['queue_id'] ?? 0);
        if ($operation === 'get') {
            return self::$rows[$queueId] ?? null;
        }

        if ($operation === 'update') {
            self::$updates[] = $params;
            $patch = \is_array($params['patch'] ?? null) ? $params['patch'] : [];
            if ($queueId > 0) {
                self::$rows[$queueId] = \array_replace(self::$rows[$queueId] ?? ['queue_id' => $queueId], $patch);
            }

            return ['success' => true];
        }

        return null;
    }
}

final class QueueDbWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueDbWriterWQuerySpy::reset();
    }

    protected function tearDown(): void
    {
        QueueDbWriterWQuerySpy::reset();
        parent::tearDown();
    }

    public function testResolveWorkspaceEventEnvelopePromotesLogEventTypeAndFlattensPayload(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $method = new ReflectionMethod(QueueDbWriter::class, 'resolveWorkspaceEventEnvelope');
        $method->setAccessible(true);

        $result = $method->invoke($writer, 'log', [
            'event_type' => 'plan_saved',
            'message' => '阶段一方案已保存',
            'level' => 'done',
            'payload' => [
                'operation' => 'plan',
                'details' => ['round' => 2],
            ],
            'stage_code' => 'plan',
            'event_id' => 99,
            'created_at' => '2026-04-19 19:00:00',
        ]);

        self::assertIsArray($result);
        self::assertSame('plan_saved', $result[0]);
        self::assertSame('done', $result[2]);
        self::assertIsArray($result[1]);
        self::assertSame('阶段一方案已保存', (string)($result[1]['message'] ?? ''));
        self::assertSame('plan', (string)($result[1]['operation'] ?? ''));
        self::assertSame('plan', (string)($result[1]['stage'] ?? ''));
        self::assertSame(['round' => 2], $result[1]['details'] ?? []);
        self::assertArrayNotHasKey('payload', $result[1]);
        self::assertArrayNotHasKey('event_type', $result[1]);
        self::assertArrayNotHasKey('stage_code', $result[1]);
        self::assertArrayNotHasKey('event_id', $result[1]);
        self::assertArrayNotHasKey('created_at', $result[1]);
    }

    public function testResolveWorkspaceEventEnvelopeDefaultsLevelForWarnings(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'task_plan');
        $method = new ReflectionMethod(QueueDbWriter::class, 'resolveWorkspaceEventEnvelope');
        $method->setAccessible(true);

        $result = $method->invoke($writer, 'warning', [
            'message' => '队列任务仍在后台执行',
            'operation' => 'task_plan',
        ]);

        self::assertIsArray($result);
        self::assertSame('warning', $result[0]);
        self::assertSame('warning', $result[2]);
        self::assertSame('task_plan', (string)($result[1]['operation'] ?? ''));
    }

    public function testEnrichOperationCorrelationPayloadAddsQueueContext(): void
    {
        $writer = new QueueDbWriter(
            1,
            1,
            77,
            AiSiteAgentSession::STAGE_PLAN,
            'plan',
            'token-abc',
            'job-key-abc',
            'stage1.requirement_expand'
        );
        $method = new ReflectionMethod(QueueDbWriter::class, 'enrichOperationCorrelationPayload');
        $method->setAccessible(true);

        $result = $method->invoke($writer, ['operation' => 'plan']);

        self::assertSame(77, (int)($result['queue_id'] ?? 0));
        self::assertSame('token-abc', (string)($result['execution_token'] ?? ''));
        self::assertSame('job-key-abc', (string)($result['job_key'] ?? ''));
        self::assertSame('stage1.requirement_expand', (string)($result['job_type'] ?? ''));
    }

    public function testSanitizePayloadForQueueEventSuppressesGeneratedContent(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $method = new ReflectionMethod(QueueDbWriter::class, 'sanitizePayloadForQueueEvent');
        $method->setAccessible(true);

        $result = $method->invoke($writer, 'chunk', [
            'message' => '# 真实生成内容',
            'chunk' => '# 真实生成内容',
            'content' => '# 真实生成内容',
            'operation' => 'plan',
            'stage' => AiSiteAgentSession::STAGE_PLAN,
        ]);

        self::assertIsArray($result);
        self::assertTrue((bool)($result['suppressed_content'] ?? false));
        self::assertSame('plan', (string)($result['operation'] ?? ''));
        self::assertSame(AiSiteAgentSession::STAGE_PLAN, (string)($result['stage'] ?? ''));
        self::assertNotSame('', \trim((string)($result['message'] ?? '')));
        self::assertArrayNotHasKey('chunk', $result);
        self::assertArrayNotHasKey('content', $result);
        self::assertGreaterThan(0, (int)($result['suppressed_content_bytes'] ?? 0));
    }

    public function testSanitizePayloadForQueueEventPrunesHeavyCheckpointState(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build');
        $method = new ReflectionMethod(QueueDbWriter::class, 'sanitizePayloadForQueueEvent');
        $method->setAccessible(true);

        $result = $method->invoke($writer, 'task_completed', [
            'message' => 'Task completed',
            'operation' => 'build',
            'task_key' => 'home.hero',
            'task_type' => 'page_section',
            'state' => [
                'virtual_pages_by_type' => [
                    'home_page' => [
                        'blocks' => \array_fill(0, 80, ['html' => \str_repeat('x', 128)]),
                    ],
                ],
                'top_logs' => \array_fill(0, 50, ['message' => \str_repeat('y', 64)]),
            ],
            'details' => [
                'html' => \str_repeat('z', 2048),
                'section_code' => 'hero',
            ],
        ]);

        self::assertIsArray($result);
        self::assertSame('Task completed', (string)($result['message'] ?? ''));
        self::assertSame('home.hero', (string)($result['task_key'] ?? ''));
        self::assertArrayNotHasKey('state', $result);
        self::assertFalse((bool)($result['state_loaded'] ?? true));
        self::assertSame('state', (string)($result['state_ref'] ?? ''));
        self::assertGreaterThan(0, (int)($result['state_bytes'] ?? 0));

        $details = $result['details'] ?? [];
        self::assertIsArray($details);
        self::assertSame('hero', (string)($details['section_code'] ?? ''));
        self::assertArrayNotHasKey('html', $details);
        self::assertFalse((bool)($details['html_loaded'] ?? true));

        $reflection = new \ReflectionClass(QueueDbWriter::class);
        $maxBytes = $reflection->getConstant('QUEUE_EVENT_PAYLOAD_MAX_BYTES');
        self::assertIsInt($maxBytes);
        self::assertLessThanOrEqual(
            $maxBytes,
            \strlen(\json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
        );
    }

    public function testIsContentBearingStreamPayloadTreatsThinkingAsSuppressedStream(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $method = new ReflectionMethod(QueueDbWriter::class, 'isContentBearingStreamPayload');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($writer, 'thinking', [
            'content' => 'reasoning content chunk',
            'event_type' => 'thinking',
        ]));
        self::assertTrue((bool)$method->invoke($writer, 'log', [
            'stream_stage' => 'reasoning_chunk',
            'message' => 'intermediate reasoning stream',
        ]));
        self::assertTrue((bool)$method->invoke($writer, 'log', [
            'payload' => [
                'format' => 'reasoning_stream',
            ],
        ]));
        self::assertTrue((bool)$method->invoke($writer, 'log', [
            'event_type' => 'plan_chunk',
            'message' => '### section markdown block',
            'payload' => [
                'format' => 'markdown_block',
            ],
        ]));
    }

    public function testProgressAndChunkDoNotAppendFullQueueResultHistory(): void
    {
        QueueDbWriterWQuerySpy::reset([
            77 => [
                'queue_id' => 77,
                'result' => 'existing terminal log',
                'content' => '{}',
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 77, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $this->discardCliMirrorOutput(static function () use ($writer): void {
            $writer->sendEvent('progress', [
                'event_type' => 'progress',
                'message' => '普通进度只更新 process',
            ]);
            $writer->sendEvent('chunk', [
                'chunk' => 'streamed markdown body must stay out of queue.result',
            ]);
        });

        self::assertCount(1, QueueDbWriterWQuerySpy::$updates);
        $progressPatch = QueueDbWriterWQuerySpy::$updates[0]['patch'] ?? [];
        self::assertIsArray($progressPatch);
        self::assertSame('普通进度只更新 process', $progressPatch['process'] ?? null);
        self::assertArrayNotHasKey('result', $progressPatch);
    }

    public function testTokenUsageAndAiProgressUpdateProcessAndContentWithoutResultHistory(): void
    {
        QueueDbWriterWQuerySpy::reset([
            88 => [
                'queue_id' => 88,
                'result' => '',
                'content' => '{"operation":"plan"}',
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 88, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $this->discardCliMirrorOutput(static function () use ($writer): void {
            $writer->recordTokenUsage([
                'prompt_tokens' => 120,
                'completion_tokens' => 34,
            ], ['provider' => 'unit']);
            $writer->recordRawAiStreamChunk(\str_repeat('x', 65536));
        });

        self::assertCount(2, QueueDbWriterWQuerySpy::$updates);

        $usagePatch = QueueDbWriterWQuerySpy::$updates[0]['patch'] ?? [];
        self::assertIsArray($usagePatch);
        self::assertArrayHasKey('content', $usagePatch);
        self::assertArrayHasKey('process', $usagePatch);
        self::assertArrayNotHasKey('result', $usagePatch);
        $content = \json_decode((string)$usagePatch['content'], true);
        self::assertIsArray($content);
        self::assertSame(120, $content['token_usage']['input_tokens'] ?? null);
        self::assertSame(34, $content['token_usage']['output_tokens'] ?? null);
        self::assertSame(154, $content['token_usage']['total_tokens'] ?? null);

        $aiProgressPatch = QueueDbWriterWQuerySpy::$updates[1]['patch'] ?? [];
        self::assertIsArray($aiProgressPatch);
        self::assertArrayHasKey('process', $aiProgressPatch);
        $aiProgressProcess = (string)$aiProgressPatch['process'];
        self::assertNotSame('', \trim($aiProgressProcess));
        self::assertStringContainsString('65536', $aiProgressProcess);
        self::assertStringNotContainsString(\str_repeat('x', 128), $aiProgressProcess);
        self::assertArrayNotHasKey('result', $aiProgressPatch);
        self::assertArrayNotHasKey('content', $aiProgressPatch);
    }

    public function testTerminalErrorAndCheckpointCanPersistQueueResultAndSessionEvent(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $shouldPersistQueue = new ReflectionMethod(QueueDbWriter::class, 'shouldPersistQueueResultLine');
        $shouldPersistQueue->setAccessible(true);
        $shouldPersistSession = new ReflectionMethod(QueueDbWriter::class, 'shouldPersistSessionEvent');
        $shouldPersistSession->setAccessible(true);
        $buildPatch = new ReflectionMethod(QueueDbWriter::class, 'buildQueueResultPatch');
        $buildPatch->setAccessible(true);

        foreach ([
            ['error', ['message' => 'terminal failure']],
            ['progress', ['message' => 'checkpoint saved', 'checkpoint' => ['step' => 'theme']]],
            ['progress', ['message' => 'terminal summary', 'terminal_summary' => true]],
            ['progress', ['message' => 'queue done', 'status' => 'done']],
        ] as [$event, $payload]) {
            self::assertTrue((bool)$shouldPersistQueue->invoke($writer, $event, $payload), $event);
            self::assertTrue((bool)$shouldPersistSession->invoke($writer, $event, $payload), $event);
        }

        $patch = $buildPatch->invoke($writer, '[12:00:00] ERROR terminal failure', 'terminal failure');
        self::assertIsArray($patch);
        self::assertSame('terminal failure', $patch['process'] ?? null);
        self::assertStringContainsString('ERROR terminal failure', (string)($patch['result'] ?? ''));
    }

    public function testTransientTelemetryDoesNotPersistSessionEventHistory(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $shouldPersistSession = new ReflectionMethod(QueueDbWriter::class, 'shouldPersistSessionEvent');
        $shouldPersistSession->setAccessible(true);
        $shouldPersistQueue = new ReflectionMethod(QueueDbWriter::class, 'shouldPersistQueueResultLine');
        $shouldPersistQueue->setAccessible(true);

        foreach ([
            ['progress', ['event_type' => 'progress', 'message' => 'ordinary progress']],
            ['ai_progress', ['event_type' => 'ai_progress', 'message' => 'stream telemetry']],
            ['token_usage', ['event_type' => 'token_usage', 'message' => 'input=1 output=2 total=3']],
            ['log', ['event_type' => 'plan_chunk', 'message' => 'markdown block body', 'payload' => ['format' => 'markdown_block']]],
        ] as [$event, $payload]) {
            self::assertFalse((bool)$shouldPersistQueue->invoke($writer, $event, $payload), $event);
            self::assertFalse((bool)$shouldPersistSession->invoke($writer, $event, $payload), $event);
        }
    }

    public function testQueueResultCacheKeepsTerminalResultWithinFourKilobytes(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'task_plan');
        $appendMethod = new ReflectionMethod(QueueDbWriter::class, 'appendLineToQueueResultCache');
        $appendMethod->setAccessible(true);

        $reflection = new \ReflectionClass(QueueDbWriter::class);
        $maxBytes = $reflection->getConstant('QUEUE_RESULT_MAX_BYTES');
        $marker = $reflection->getConstant('QUEUE_RESULT_TRUNCATION_MARKER');

        self::assertIsInt($maxBytes);
        self::assertIsString($marker);

        $cache = '';
        $cache = $appendMethod->invoke($writer, $cache, \str_repeat('A', (int)$maxBytes + 128) . "\nterminal-tail-line");

        self::assertLessThanOrEqual($maxBytes, \strlen($cache));
        self::assertStringStartsWith($marker, $cache);
        self::assertStringContainsString('terminal-tail-line', $cache);
    }

    private function discardCliMirrorOutput(callable $callback): void
    {
        \ob_start();
        try {
            $callback();
        } finally {
            \ob_end_clean();
        }
    }
}

}
