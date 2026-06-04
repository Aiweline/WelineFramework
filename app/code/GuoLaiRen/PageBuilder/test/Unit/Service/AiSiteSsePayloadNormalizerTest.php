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
            'plan_json_execution_summary' => ['total' => 2, 'done' => 1],
        ]);

        self::assertSame('running', $payload['queue_status']);
        self::assertSame('Pending', $payload['status']);
        self::assertSame('Done', $payload['job_status']);
        self::assertSame(['total' => 2, 'done' => 1], $payload['plan_json_execution_summary']);
    }

    public function testNormalizeDoesNotFillOldAliases(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'status' => 'Running',
            'task_progress' => ['total' => 1],
            'plan_json_task_summary' => ['total' => 2],
        ]);

        self::assertArrayNotHasKey('queue_status', $payload);
        self::assertArrayNotHasKey('task_summary', $payload);
        self::assertSame(['total' => 1], $payload['task_progress']);
        self::assertSame(['total' => 2], $payload['plan_json_task_summary']);
    }

    public function testNormalizeIsIdempotent(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $first = $normalizer->normalize([
            'queue_status' => 'running',
            'plan_json_execution_summary' => ['total' => 3],
            'operation' => 'build',
        ]);

        self::assertSame($first, $normalizer->normalize($first));
    }

    public function testAuthoritativeEventNamesUsePlanJsonBlockEvents(): void
    {
        $events = AiSiteSsePayloadNormalizer::authoritativeEventNames();

        foreach ([
            'start',
            'progress',
            'chunk',
            'done',
            'error',
            'plan_json_block_completed',
            'plan_json_block_failed',
            'task_progress',
            'task_completed',
            'task_failed',
        ] as $eventName) {
            self::assertContains($eventName, $events);
        }

        self::assertNotContains('plan_json_progress', $events);
        foreach ($events as $name) {
            self::assertSame($name, \strtolower($name));
            self::assertSame(1, \preg_match('/^[a-z][a-z0-9_]*$/', $name));
        }
    }

    public function testNoUnsupportedAliasMapsRemain(): void
    {
        self::assertSame([], AiSiteSsePayloadNormalizer::aliasToAuthoritativeMap());
        self::assertSame([], AiSiteSsePayloadNormalizer::authoritativeToAliasesMap());
    }
}
