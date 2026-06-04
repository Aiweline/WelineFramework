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

    public function testSanitizePayloadForQueueEventPrunesHeavyState(): void
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
                        'block_nodes' => \array_fill(0, 80, ['html' => \str_repeat('x', 128)]),
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

    public function testStageOnePageProgressIsPersistedAsBoundedQueueContent(): void
    {
        $this->expectOutputRegex('/PROGRESS Stage 1 page fanout/');
        QueueDbWriterWQuerySpy::reset([
            7 => [
                'queue_id' => 7,
                'content' => \json_encode(['public_id' => 'pid-7', 'existing' => 'keep'], \JSON_UNESCAPED_UNICODE),
            ],
        ]);
        $writer = new QueueDbWriter(1, 1, 7, AiSiteAgentSession::STAGE_PLAN, 'plan');

        $writer->sendEvent('progress', [
            'message' => 'Stage 1 page fanout',
            'operation' => 'plan',
            'stage1_page_progress' => [
                'total' => 2,
                'concurrency' => 5,
                'running' => ['home_page'],
                'done' => ['about_page'],
                'details' => [
                    [
                        'page_type' => 'home_page',
                        'status' => 'running',
                        'message' => 'Generating blocks',
                        'block_rows' => [
                            [
                                'block_key' => 'hero',
                                'status' => 'running',
                                'message' => \str_repeat('x', 300),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $row = QueueDbWriterWQuerySpy::$rows[7] ?? [];
        self::assertIsArray($row);
        $content = \json_decode((string)($row['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame('keep', (string)($content['existing'] ?? ''));
        self::assertIsArray($content['stage1_page_progress'] ?? null);
        $progress = $content['stage1_page_progress'];
        self::assertSame(2, (int)($progress['total'] ?? 0));
        self::assertSame(5, (int)($progress['concurrency'] ?? 0));
        self::assertSame(['home_page'], $progress['running'] ?? []);
        self::assertSame(['about_page'], $progress['done'] ?? []);
        self::assertIsArray($progress['details'] ?? null);
        self::assertSame('home_page', (string)($progress['details'][0]['page_type'] ?? ''));
        self::assertSame('hero', (string)($progress['details'][0]['block_rows'][0]['block_key'] ?? ''));
        self::assertLessThanOrEqual(160, \strlen((string)($progress['details'][0]['block_rows'][0]['message'] ?? '')));
    }

    public function testBuildProgressIsPersistedAsBoundedQueueContent(): void
    {
        QueueDbWriterWQuerySpy::reset([
            8 => [
                'queue_id' => 8,
                'content' => \json_encode(['public_id' => 'pid-8', 'existing' => 'keep'], \JSON_UNESCAPED_UNICODE),
            ],
        ]);
        $writer = new QueueDbWriter(1, 1, 8, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build');

        $this->discardCliMirrorOutput(static function () use ($writer): void {
            $writer->sendEvent('progress', [
                'message' => 'Build progress',
                'operation' => 'build',
                'progress_percent' => 42,
                'active_concurrency' => 5,
                'plan_json_task_summary' => [
                    'total' => 3,
                    'done' => 1,
                    'running' => 2,
                    'pending' => 0,
                    'failed' => 0,
                    'groups' => [
                        'home_page' => [
                            'page_type' => 'home_page',
                            'total' => 2,
                            'done' => 1,
                            'running' => 1,
                            'tasks' => [
                                [
                                    'task_key' => 'home_page:hero',
                                    'label' => 'Hero',
                                    'section_code' => 'hero',
                                    'page_type' => 'home_page',
                                    'status' => 'done',
                                    'message' => \str_repeat('x', 260),
                                ],
                            ],
                        ],
                    ],
                ],
                'page_block_progress' => [
                    ['page_type' => 'home_page', 'done' => 1, 'total' => 2, 'running' => 1],
                ],
                'plan_json_block_progress' => [
                    [
                        'page_type' => 'home_page',
                        'block_id' => 'home_page:hero',
                        'section_key' => 'hero',
                        'label' => 'Hero',
                        'status' => 'completed',
                        'message' => \str_repeat('y', 260),
                        'html' => \str_repeat('<div>heavy</div>', 100),
                    ],
                ],
            ]);
        });

        $row = QueueDbWriterWQuerySpy::$rows[8] ?? [];
        self::assertIsArray($row);
        $content = \json_decode((string)($row['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame('keep', (string)($content['existing'] ?? ''));
        self::assertSame(42, (int)($content['progress_percent'] ?? 0));
        self::assertSame(5, (int)($content['active_concurrency'] ?? 0));
        self::assertSame(3, (int)($content['plan_json_task_summary']['total'] ?? 0));
        self::assertSame(2, (int)($content['plan_json_task_summary']['running'] ?? 0));
        self::assertLessThanOrEqual(180, \strlen((string)($content['plan_json_task_summary']['groups']['home_page']['tasks'][0]['message'] ?? '')));
        self::assertSame('home_page', (string)($content['page_block_progress'][0]['page_type'] ?? ''));
        self::assertSame(1, (int)($content['page_block_progress'][0]['running'] ?? 0));
        self::assertSame('done', (string)($content['plan_json_block_progress'][0]['status'] ?? ''));
        self::assertArrayNotHasKey('html', $content['plan_json_block_progress'][0]);
        self::assertLessThanOrEqual(160, \strlen((string)($content['plan_json_block_progress'][0]['message'] ?? '')));

        $patch = QueueDbWriterWQuerySpy::$updates[0]['patch'] ?? [];
        self::assertIsArray($patch);
        self::assertArrayNotHasKey('result', $patch);
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

    public function testTerminalErrorAndTerminalSummaryCanPersistQueueResultAndSessionEvent(): void
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
            ['progress', ['message' => 'terminal summary', 'terminal_summary' => true]],
            ['progress', ['message' => 'queue done', 'status' => 'done']],
        ] as [$event, $payload]) {
            self::assertTrue((bool)$shouldPersistQueue->invoke($writer, $event, $payload), $event);
            self::assertTrue((bool)$shouldPersistSession->invoke($writer, $event, $payload), $event);
        }

        $staleTransientPayload = ['message' => 'prior transient payload', 'checkpoint' => ['step' => 'theme']];
        self::assertFalse((bool)$shouldPersistQueue->invoke($writer, 'progress', $staleTransientPayload));
        self::assertFalse((bool)$shouldPersistSession->invoke($writer, 'progress', $staleTransientPayload));

        $patch = $buildPatch->invoke($writer, '[12:00:00] ERROR terminal failure', 'terminal failure');
        self::assertIsArray($patch);
        self::assertSame('terminal failure', $patch['process'] ?? null);
        self::assertStringContainsString('ERROR terminal failure', (string)($patch['result'] ?? ''));
    }

    public function testRemovedTransientPayloadsAreDiscardedBeforePersistence(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        $sanitize = new ReflectionMethod(QueueDbWriter::class, 'sanitizePayloadForQueueEvent');
        $sanitize->setAccessible(true);

        $payload = $sanitize->invoke($writer, 'progress', [
            'message' => 'status update',
            'snapshot' => ['large' => true],
            'queue_snapshot' => ['large' => true],
            'checkpoint' => ['step' => 'theme'],
            'payload' => [
                'snapshot' => ['large' => true],
                'queue_snapshot' => ['large' => true],
                'checkpoint' => ['step' => 'nested'],
            ],
        ]);

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('snapshot', $payload);
        self::assertArrayNotHasKey('queue_snapshot', $payload);
        self::assertArrayNotHasKey('checkpoint', $payload);
        self::assertIsArray($payload['payload'] ?? null);
        self::assertArrayNotHasKey('snapshot', $payload['payload']);
        self::assertArrayNotHasKey('queue_snapshot', $payload['payload']);
        self::assertArrayNotHasKey('checkpoint', $payload['payload']);
    }

    public function testPlanJsonBlockProgressIsCompactedBeforeQueuePersistence(): void
    {
        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build');
        $sanitize = new ReflectionMethod(QueueDbWriter::class, 'sanitizePayloadForQueueEvent');
        $sanitize->setAccessible(true);

        $payload = $sanitize->invoke($writer, 'progress', [
            'message' => 'block progress',
            'state' => [
                'plan_json_block_progress' => [
                    [
                        'page_type' => 'home_page',
                        'block_id' => 'home:hero',
                        'section_key' => 'hero',
                        'label' => 'Hero',
                        'status' => 'completed',
                        'message' => \str_repeat('x', 300),
                        'updated_at' => '2026-06-02 10:00:00',
                        'html' => \str_repeat('<div>heavy</div>', 100),
                    ],
                ],
            ],
        ]);

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('state', $payload);
        self::assertSame(false, $payload['state_loaded'] ?? null);
        self::assertIsArray($payload['plan_json_block_progress'] ?? null);
        self::assertCount(1, $payload['plan_json_block_progress']);
        self::assertSame('home_page', $payload['plan_json_block_progress'][0]['page_type'] ?? null);
        self::assertSame('done', $payload['plan_json_block_progress'][0]['status'] ?? null);
        self::assertArrayNotHasKey('html', $payload['plan_json_block_progress'][0]);
        self::assertLessThanOrEqual(160, \strlen((string)($payload['plan_json_block_progress'][0]['message'] ?? '')));
    }

    public function testStageOneProgressUpdatesBypassDuplicateSummarySuppression(): void
    {
        QueueDbWriterWQuerySpy::reset([
            1 => [
                'queue_id' => 1,
                'content' => \json_encode(['public_id' => 'pid-1'], \JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        \ob_start();
        try {
            $writer->sendEvent('progress', [
                'message' => 'Stage 1 page fanout',
                'operation' => 'plan',
                'stage1_page_progress' => [
                    'total' => 2,
                    'running' => ['home_page'],
                    'done' => [],
                    'pending' => ['about_page'],
                    'done_count' => 0,
                    'running_count' => 1,
                    'pending_count' => 1,
                    'remaining_count' => 2,
                ],
            ]);
            $writer->sendEvent('progress', [
                'message' => 'Stage 1 page fanout',
                'operation' => 'plan',
                'stage1_page_progress' => [
                    'total' => 2,
                    'running' => ['about_page'],
                    'done' => ['home_page'],
                    'pending' => [],
                    'done_count' => 1,
                    'running_count' => 1,
                    'pending_count' => 0,
                    'remaining_count' => 1,
                ],
            ]);
        } finally {
            \ob_end_clean();
        }

        self::assertCount(2, QueueDbWriterWQuerySpy::$updates);
        $lastPatch = QueueDbWriterWQuerySpy::$updates[1]['patch'] ?? [];
        self::assertIsArray($lastPatch);
        $content = \json_decode((string)($lastPatch['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame(['home_page'], $content['stage1_page_progress']['done'] ?? null);
        self::assertSame(['about_page'], $content['stage1_page_progress']['running'] ?? null);
    }

    public function testBuildProgressUpdatesBypassDuplicateSummarySuppression(): void
    {
        QueueDbWriterWQuerySpy::reset([
            1 => [
                'queue_id' => 1,
                'content' => \json_encode(['public_id' => 'pid-1'], \JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build');
        \ob_start();
        try {
            $writer->sendEvent('progress', [
                'message' => 'Build progress',
                'operation' => 'build',
                'plan_json_task_summary' => [
                    'total' => 2,
                    'done' => 0,
                    'running' => 1,
                    'pending' => 1,
                    'groups' => [
                        'home_page' => ['page_type' => 'home_page', 'total' => 1, 'running' => 1],
                    ],
                ],
                'page_block_progress' => [
                    ['page_type' => 'home_page', 'done' => 0, 'total' => 1, 'running' => 1],
                ],
            ]);
            $writer->sendEvent('progress', [
                'message' => 'Build progress',
                'operation' => 'build',
                'plan_json_task_summary' => [
                    'total' => 2,
                    'done' => 1,
                    'running' => 1,
                    'pending' => 0,
                    'groups' => [
                        'home_page' => ['page_type' => 'home_page', 'total' => 1, 'done' => 1],
                        'about_page' => ['page_type' => 'about_page', 'total' => 1, 'running' => 1],
                    ],
                ],
                'page_block_progress' => [
                    ['page_type' => 'home_page', 'done' => 1, 'total' => 1],
                    ['page_type' => 'about_page', 'done' => 0, 'total' => 1, 'running' => 1],
                ],
            ]);
        } finally {
            \ob_end_clean();
        }

        self::assertCount(2, QueueDbWriterWQuerySpy::$updates);
        $lastPatch = QueueDbWriterWQuerySpy::$updates[1]['patch'] ?? [];
        self::assertIsArray($lastPatch);
        $content = \json_decode((string)($lastPatch['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame(1, (int)($content['plan_json_task_summary']['done'] ?? 0));
        self::assertSame('about_page', (string)($content['page_block_progress'][1]['page_type'] ?? ''));
    }

    public function testLargeQueueContentProgressMergeDoesNotDecodeWholePayload(): void
    {
        $largeContent = '{"public_id":"pid-1","blob":"' . \str_repeat('x', 270000) . '"}';
        QueueDbWriterWQuerySpy::reset([
            1 => [
                'queue_id' => 1,
                'content' => $largeContent,
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        \ob_start();
        try {
            $writer->sendEvent('progress', [
                'message' => 'Stage 1 page fanout',
                'operation' => 'plan',
                'stage1_page_progress' => [
                    'total' => 1,
                    'done' => ['home_page'],
                    'done_count' => 1,
                    'remaining_count' => 0,
                ],
            ]);
        } finally {
            \ob_end_clean();
        }

        self::assertCount(1, QueueDbWriterWQuerySpy::$updates);
        $content = (string)(QueueDbWriterWQuerySpy::$updates[0]['patch']['content'] ?? '');
        self::assertStringContainsString('"blob":"', $content);
        self::assertStringContainsString('"stage1_page_progress"', $content);
        self::assertStringContainsString('"done":["home_page"]', $content);
        self::assertGreaterThan(\strlen($largeContent), \strlen($content));
    }

    public function testLargeQueueContentProgressMergeOnlyTouchesTopLevelProgress(): void
    {
        $largeContent = \json_encode([
            'public_id' => 'pid-1',
            'nested' => [
                'stage1_page_progress' => [
                    'done' => ['nested_should_remain'],
                ],
            ],
            'blob' => \str_repeat('x', 270000),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        self::assertIsString($largeContent);
        QueueDbWriterWQuerySpy::reset([
            1 => [
                'queue_id' => 1,
                'content' => $largeContent,
            ],
        ]);

        $writer = new QueueDbWriter(1, 1, 1, AiSiteAgentSession::STAGE_PLAN, 'plan');
        \ob_start();
        try {
            $writer->sendEvent('progress', [
                'message' => 'Stage 1 page fanout',
                'operation' => 'plan',
                'stage1_page_progress' => [
                    'total' => 1,
                    'done' => ['home_page'],
                    'done_count' => 1,
                    'remaining_count' => 0,
                ],
            ]);
        } finally {
            \ob_end_clean();
        }

        self::assertCount(1, QueueDbWriterWQuerySpy::$updates);
        $content = \json_decode((string)(QueueDbWriterWQuerySpy::$updates[0]['patch']['content'] ?? ''), true);
        self::assertIsArray($content);
        self::assertSame(['nested_should_remain'], $content['nested']['stage1_page_progress']['done'] ?? null);
        self::assertSame(['home_page'], $content['stage1_page_progress']['done'] ?? null);
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
