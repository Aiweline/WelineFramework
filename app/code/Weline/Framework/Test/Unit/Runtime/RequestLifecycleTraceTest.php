<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

class RequestLifecycleTraceTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::leave();
        Runtime::resetModeCache();
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

    public function testShouldSkipForAiWorkbenchRoutesInWlsMode(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context([
            'input' => ['uri' => '/pagebuilder/backend/ai-site-agent/workspace'],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        self::assertTrue(RequestLifecycleTrace::shouldSkipForCurrentRequest());
    }

    public function testShouldNotSkipForRegularBackendRouteInWlsMode(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context([
            'input' => ['uri' => '/admin/dashboard/index'],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        self::assertFalse(RequestLifecycleTrace::shouldSkipForCurrentRequest());
    }

    public function testMaxSpanCapClearsBufferedSpansAndDisablesRecordingUntilReset(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        $this->setStaticProperty('maxSpansCapCache', 1);
        $this->setStaticProperty('maxSpansLogged', true);

        RequestLifecycleTrace::recordSpan('first', 1.0);
        self::assertCount(1, RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::recordSpan('second', 1.0);
        self::assertSame([], RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::recordSpan('third', 1.0);
        self::assertSame([], RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::reset();
        $this->setStaticProperty('maxSpansCapCache', 1);
        RequestLifecycleTrace::recordSpan('after_reset', 1.0);
        self::assertCount(1, RequestLifecycleTrace::getSpans());
    }

    /**
     * @param array<int, array<string, mixed>> $spans
     */
    private function setSpans(array $spans): void
    {
        $this->setStaticProperty('spans', $spans);
    }

    private function setStaticProperty(string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(RequestLifecycleTrace::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
