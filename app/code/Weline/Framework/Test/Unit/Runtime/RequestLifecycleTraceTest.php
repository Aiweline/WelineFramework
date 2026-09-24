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
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::clearPanelTplPerf();
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

        RequestLifecycleTrace::installPanelTraceOn();
        RequestLifecycleTrace::recordSpan('router_start', 1.0, 'framework');

        self::assertSame([
            ['name' => 'router_start', 'duration_ms' => 1.0, 'category' => 'framework'],
        ], RequestLifecycleTrace::getSpans());
    }

    public function testDevModeWithoutPanelOpenDoesNotEnableTrace(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::clearPanelTrace();

        self::assertFalse(RequestLifecycleTrace::isEnabled());
        RequestLifecycleTrace::recordSpan('should_not_record', 1.0, 'framework');
        self::assertSame([], RequestLifecycleTrace::getSpans());
    }

    public function testPanelOpenEnablesTraceAndCloseDisables(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));

        RequestLifecycleTrace::installPanelTraceOn();
        self::assertTrue(RequestLifecycleTrace::isPanelTraceArmed());
        self::assertTrue(RequestLifecycleTrace::isEnabled());

        RequestLifecycleTrace::recordSpan('armed_span', 2.5, 'framework');
        self::assertSame([
            ['name' => 'armed_span', 'duration_ms' => 2.5, 'category' => 'framework'],
        ], RequestLifecycleTrace::getSpans());

        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        Context::leave();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        self::assertFalse(RequestLifecycleTrace::isPanelTraceArmed());
        self::assertFalse(RequestLifecycleTrace::isEnabled());
    }

    public function testEnvRequestTraceConfigDoesNotEnableWithoutPanel(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::clearPanelTrace();
        if (\class_exists(\Weline\Framework\App\Env::class, false)) {
            \Weline\Framework\App\Env::getInstance()->applyRuntimeConfig([
                'wls' => ['debug' => ['request_trace' => true]],
            ]);
        }
        self::assertFalse(RequestLifecycleTrace::isEnabled());
    }

    public function testForgedPanelTraceCookieIsRejected(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        $_COOKIE[RequestLifecycleTrace::panelTraceCookieName()] = 'not-a-valid-panel-cookie';
        self::assertFalse(RequestLifecycleTrace::isPanelTraceArmed());
        self::assertFalse(RequestLifecycleTrace::isEnabled());
    }

    public function testPanelTplPerfCookieArmsTemplateOverlayWithoutQuery(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::clearPanelTplPerf();
        self::assertFalse(RequestLifecycleTrace::isPanelTplPerfArmed());
        self::assertFalse(RequestLifecycleTrace::isTemplatePerfOverlayRequested());

        RequestLifecycleTrace::installPanelTplPerfOn();
        self::assertTrue(RequestLifecycleTrace::isPanelTplPerfArmed());
        self::assertTrue(RequestLifecycleTrace::isTemplatePerfOverlayRequested());

        RequestLifecycleTrace::clearPanelTplPerf();
        RequestLifecycleTrace::reset();
        Context::leave();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        self::assertFalse(RequestLifecycleTrace::isPanelTplPerfArmed());
    }

    public function testForgedPanelTplPerfCookieIsRejected(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::clearPanelTplPerf();
        $_COOKIE[RequestLifecycleTrace::panelTplPerfCookieName()] = 'forged-tpl-perf';
        self::assertFalse(RequestLifecycleTrace::isPanelTplPerfArmed());
        self::assertFalse(RequestLifecycleTrace::isTemplatePerfOverlayRequested());
    }

    public function testWlsControlPlaneWithoutRequestContextStaysDisabledEvenWhenDebugIsOn(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        // Simulate Master: WLS persistent, no RequestContext initialized, DEBUG forced on.
        if (!\defined('DEBUG')) {
            \define('DEBUG', true);
        }
        Context::enter(new Context([]));
        RequestLifecycleTrace::installPanelTraceOn();

        self::assertFalse(RequestLifecycleTrace::isEnabled());

        for ($i = 0; $i < 5000; $i++) {
            RequestLifecycleTrace::recordSpan('master_leak_' . $i, 0.01, 'framework');
        }

        self::assertSame([], RequestLifecycleTrace::getSpans());
        // Must remain uncached so a later real request can still enable tracing.
        self::assertFalse(RequestLifecycleTrace::isEnabled());
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
        RequestLifecycleTrace::installPanelTraceOn();
        $this->setStateProperty('maxSpansCapCache', 1);
        $this->setStateProperty('maxSpansLogged', true);

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
        RequestLifecycleTrace::installPanelTraceOn();
        $this->setStateProperty('maxSpansCapCache', 1);
        RequestLifecycleTrace::recordSpan('after_reset', 1.0);
        self::assertCount(1, RequestLifecycleTrace::getSpans());
    }

    public function testDatabaseTraceRedactsAuthenticationDigestStatements(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::installPanelTraceOn();
        $digest = \str_repeat('a', 64);

        RequestLifecycleTrace::recordSpan(
            'db::insert::weline_remembered_device_credential',
            1.25,
            'db',
            null,
            [
                'sql' => "INSERT INTO weline_remembered_device_credential (token_digest) VALUES ('{$digest}')",
                'operation' => 'insert',
                'table' => 'weline_remembered_device_credential',
            ],
        );

        $span = RequestLifecycleTrace::getSpans()[0];
        self::assertSame('[REDACTED: authentication persistence statement]', $span['meta']['sql']);
        self::assertSame('weline_remembered_device_credential', $span['meta']['table']);
        self::assertStringNotContainsString($digest, (string)json_encode($span));
    }

    /**
     * @param array<int, array<string, mixed>> $spans
     */
    private function setSpans(array $spans): void
    {
        $this->setStateProperty('spans', $spans);
    }

    private function setStateProperty(string $name, mixed $value): void
    {
        $state = (new \ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null);
        $state->{$name} = $value;
    }
}
