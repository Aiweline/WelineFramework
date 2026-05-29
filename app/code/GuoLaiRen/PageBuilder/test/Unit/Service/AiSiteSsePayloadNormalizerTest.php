<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteSsePayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteSsePayloadNormalizerTest extends TestCase
{
    public function testNormalizeOnlyTouchesCanonicalQueueStatus(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'queue_status' => 'Running',
            'status' => 'Pending',
            'job_status' => 'Done',
            'build_plan_execution_summary' => ['total' => 2, 'done' => 1],
        ]);

        self::assertSame('running', $payload['queue_status']);
        self::assertSame('Pending', $payload['status']);
        self::assertSame('Done', $payload['job_status']);
        self::assertSame(['total' => 2, 'done' => 1], $payload['build_plan_execution_summary']);
    }

    public function testNormalizeDoesNotFillOldAliases(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'status' => 'Running',
            'task_progress' => ['total' => 1],
            'build_task_summary' => ['total' => 2],
        ]);

        self::assertArrayNotHasKey('queue_status', $payload);
        self::assertArrayNotHasKey('task_summary', $payload);
        self::assertSame(['total' => 1], $payload['task_progress']);
        self::assertSame(['total' => 2], $payload['build_task_summary']);
    }

    public function testNormalizeIsIdempotent(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $first = $normalizer->normalize([
            'queue_status' => 'running',
            'build_plan_execution_summary' => ['total' => 3],
            'operation' => 'build',
        ]);

        self::assertSame($first, $normalizer->normalize($first));
    }

    public function testAuthoritativeEventNamesUseBuildPlanBlockEvents(): void
    {
        $events = AiSiteSsePayloadNormalizer::authoritativeEventNames();

        foreach ([
            'start',
            'progress',
            'chunk',
            'done',
            'error',
            'build_plan_progress',
            'build_plan_block_completed',
            'build_plan_block_failed',
        ] as $eventName) {
            self::assertContains($eventName, $events);
        }

        self::assertNotContains('task_progress', $events);
        self::assertNotContains('task_failed', $events);
        foreach ($events as $name) {
            self::assertSame($name, \strtolower($name));
            self::assertSame(1, \preg_match('/^[a-z][a-z0-9_]*$/', $name));
        }
    }

    public function testNoDeprecatedAliasMapsRemain(): void
    {
        self::assertSame([], AiSiteSsePayloadNormalizer::aliasToAuthoritativeMap());
        self::assertSame([], AiSiteSsePayloadNormalizer::authoritativeToAliasesMap());
    }
}
