<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Http\Sse;

use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QueueDbWriterTest extends TestCase
{
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
        self::assertStringContainsString('省略', (string)($result['message'] ?? ''));
        self::assertArrayNotHasKey('chunk', $result);
        self::assertArrayNotHasKey('content', $result);
        self::assertGreaterThan(0, (int)($result['suppressed_content_bytes'] ?? 0));
    }

    public function testAppendLineToQueueResultCacheKeepsRecentTailWithinLimit(): void
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
        $cache = $appendMethod->invoke($writer, $cache, \str_repeat('A', (int)$maxBytes - 4));
        $cache = $appendMethod->invoke($writer, $cache, 'tail-line');

        self::assertLessThanOrEqual($maxBytes, \strlen($cache));
        self::assertStringStartsWith($marker, $cache);
        self::assertStringContainsString('tail-line', $cache);
    }
}
