<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

class RequestLifecycleTraceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_WELINE_REQUEST_ID'], $_SERVER['HTTP_X_REQUEST_ID']);
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

    public function testEarlyWlsCheckBeforeRequestContextInitDoesNotPoisonRequestTrace(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context([]));

        self::assertFalse(RequestLifecycleTrace::isEnabled());

        Context::leave();
        Context::enter(new Context([
            'input' => ['uri' => '/USD/catalog/category/books'],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        RequestLifecycleTrace::recordSpan('router_start', 1.0, 'framework');

        self::assertSame([
            ['name' => 'router_start', 'duration_ms' => 1.0, 'category' => 'framework'],
        ], RequestLifecycleTrace::getSpans());
    }

    public function testRequestIdIsScopedByRequestContextInWlsMode(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        $_SERVER['HTTP_X_WELINE_REQUEST_ID'] = 'req-context-one';
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        self::assertSame('req-context-one', RequestLifecycleTrace::ensureRequestId());

        Context::leave();
        $_SERVER['HTTP_X_WELINE_REQUEST_ID'] = 'req-context-two';
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        self::assertSame('req-context-two', RequestLifecycleTrace::ensureRequestId());
    }

    public function testRequestIdFallsBackToStableRequestContextIdWhenTraceStorageIsCleared(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context([]));
        RequestContext::setId('ctx-stable-12345678');

        self::assertSame('ctx-stable-12345678', RequestLifecycleTrace::ensureRequestId());

        RequestContext::remove('request_lifecycle_trace.request_id');

        self::assertSame('ctx-stable-12345678', RequestLifecycleTrace::ensureRequestId());
    }

    public function testRequestIdUsesContextIdBeforeInitializedFlagIsTrue(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        $context = new Context([]);
        Context::enter($context);
        $context->set('runtime.request_context.request_id', 'ctx-early-12345678');
        $context->set('runtime.request_context.initialized', false);

        self::assertSame('ctx-early-12345678', RequestLifecycleTrace::ensureRequestId());
    }

    public function testMaxSpanCapPreservesBufferedSpansAndStopsFurtherRecordingUntilReset(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        $this->setStaticProperty('maxSpansCapCache', 1);
        $this->setStaticProperty('maxSpansLogged', true);

        RequestLifecycleTrace::recordSpan('first', 1.0);
        self::assertCount(1, RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::recordSpan('second', 1.0);
        self::assertSame([
            ['name' => 'first', 'duration_ms' => 1.0, 'category' => 'framework'],
        ], RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::recordSpan('third', 1.0);
        self::assertSame([
            ['name' => 'first', 'duration_ms' => 1.0, 'category' => 'framework'],
        ], RequestLifecycleTrace::getSpans());

        $payload = RequestLifecycleTrace::exportCompactPayload();
        self::assertSame(1, $payload['summary']['span_count']);
        self::assertTrue($payload['summary']['truncated']);
        self::assertSame(1, $payload['summary']['max_spans']);

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
