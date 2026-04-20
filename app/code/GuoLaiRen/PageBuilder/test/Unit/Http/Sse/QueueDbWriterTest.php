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
}
