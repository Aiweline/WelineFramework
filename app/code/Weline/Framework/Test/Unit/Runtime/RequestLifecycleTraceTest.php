<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestLifecycleTrace;

class RequestLifecycleTraceTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLifecycleTrace::reset();
    }

    public function testSumDurationsByNameAggregatesMatchingParentSpansOnly(): void
    {
        $this->setSpans([
            ['name' => 'dev_tool_panel', 'duration_ms' => 12.5, 'category' => 'developer'],
            ['name' => 'dev_tool_panel::render_panel', 'duration_ms' => 5.1, 'category' => 'developer', 'parent' => 'dev_tool_panel'],
            ['name' => 'dev_tool_panel', 'duration_ms' => 7.25, 'category' => 'developer'],
            ['name' => 'router_start', 'duration_ms' => 88.0, 'category' => 'framework'],
        ]);

        self::assertSame(19.75, RequestLifecycleTrace::sumDurationsByName('dev_tool_panel'));
    }

    public function testSumDurationsByNameReturnsZeroForUnknownSpan(): void
    {
        $this->setSpans([
            ['name' => 'router_start', 'duration_ms' => 88.0, 'category' => 'framework'],
        ]);

        self::assertSame(0.0, RequestLifecycleTrace::sumDurationsByName('missing_span'));
    }

    /**
     * @param array<int, array<string, mixed>> $spans
     */
    private function setSpans(array $spans): void
    {
        $property = new \ReflectionProperty(RequestLifecycleTrace::class, 'spans');
        $property->setAccessible(true);
        $property->setValue(null, $spans);
    }
}
