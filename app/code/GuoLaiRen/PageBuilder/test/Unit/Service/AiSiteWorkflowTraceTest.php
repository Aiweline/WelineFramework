<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteWorkflowTrace;
use PHPUnit\Framework\TestCase;

final class AiSiteWorkflowTraceTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('PAGE_BUILDER_AI_SITE_TRACE');
        $enabled = new \ReflectionProperty(AiSiteWorkflowTrace::class, 'enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, null);
    }

    public function testVerboseTraceCompactsPayloadBeforeJsonEncoding(): void
    {
        \putenv('PAGE_BUILDER_AI_SITE_TRACE=verbose');
        $enabled = new \ReflectionProperty(AiSiteWorkflowTrace::class, 'enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, null);

        $method = new \ReflectionMethod(AiSiteWorkflowTrace::class, 'compactValueForTrace');
        $method->setAccessible(true);

        $heavy = \str_repeat('x', 80 * 1024);
        $payload = [
            'tasks' => \array_fill(0, 200, [
                'task_key' => 'page:home_page:hero',
                'prompt' => $heavy,
            ]),
        ];

        $compacted = $method->invoke(null, $payload);
        self::assertIsArray($compacted);
        self::assertSame(40, $compacted['tasks']['_trace_truncated_items'] ?? null);
        self::assertSame(true, $compacted['tasks'][0]['prompt']['_trace_truncated_string'] ?? null);
        self::assertSame(\strlen($heavy), $compacted['tasks'][0]['prompt']['chars'] ?? null);
        self::assertLessThan(70 * 1024, \strlen(\json_encode($compacted['tasks'][0], \JSON_THROW_ON_ERROR)));
    }
}
