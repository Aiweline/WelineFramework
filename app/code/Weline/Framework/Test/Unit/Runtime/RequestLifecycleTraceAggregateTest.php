<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class RequestLifecycleTraceAggregateTest extends TestCase
{
    private array $previousDebugConfig;

    protected function setUp(): void
    {
        $this->previousDebugConfig = [
            'request_trace_max_spans' => Env::get('wls.debug.request_trace_max_spans', 4096),
        ];
        Env::getInstance()->applyRuntimeConfig([
            'wls' => ['debug' => ['request_trace_max_spans' => 2]],
        ]);
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        $this->enterRequest('aggregate-main');
        RequestLifecycleTrace::installPanelTraceOn();
    }

    protected function tearDown(): void
    {
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        Context::leave();
        Runtime::resetModeCache();
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => $this->previousDebugConfig]]);
    }

    public function testDatabaseTotalsContinueAfterDetailCapWithoutRoundingEveryCall(): void
    {
        RequestLifecycleTrace::recordSpan('db::first', 1.25, 'db', 'catalog');
        RequestLifecycleTrace::recordSpan('catalog', 20.0, 'catalog');
        RequestLifecycleTrace::recordSpan('db::second', 2.75, 'db', 'catalog');
        for ($i = 0; $i < 250; ++$i) {
            RequestLifecycleTrace::recordSpan('db::small_' . $i, 0.004, 'db', 'catalog', ['sql' => 'SELECT ' . $i]);
        }

        $payload = RequestLifecycleTrace::exportCompactPayload();
        self::assertSame(5.0, $payload['summary']['db_duration_ms']);
        self::assertSame(252, $payload['summary']['db_span_count'] ?? null);
        self::assertSame(251, $payload['summary']['dropped_span_count'] ?? null);
        self::assertSame(2, $payload['summary']['span_count']);
        self::assertTrue($payload['summary']['truncated']);
        self::assertCount(2, RequestLifecycleTrace::getSpans());
        self::assertCount(2, $payload['dict']['names']);
        self::assertSame([], $payload['dict']['metas']);
        // Parent summaries describe retained details and must not be counted as more DB calls.
        self::assertSame(1.25, RequestLifecycleTrace::getSpansWithDbSummary()[1]['db_duration_ms']);
        self::assertSame(1.25, $payload['summary']['category_totals']['db']);
    }

    public function testResetAndNextRequestDoNotReuseDatabaseTotals(): void
    {
        RequestLifecycleTrace::recordSpan('db::first', 9.0, 'db');
        RequestLifecycleTrace::reset();
        RequestLifecycleTrace::recordSpan('db::after_reset', 2.0, 'db');
        $summary = RequestLifecycleTrace::exportCompactPayload()['summary'];
        self::assertSame(2.0, $summary['db_duration_ms']);
        self::assertSame(1, $summary['db_span_count'] ?? null);

        Context::leave();
        $this->enterRequest('aggregate-next');
        RequestLifecycleTrace::recordSpan('db::next_request', 3.0, 'db');
        $summary = RequestLifecycleTrace::exportCompactPayload()['summary'];
        self::assertSame(3.0, $summary['db_duration_ms']);
        self::assertSame(1, $summary['db_span_count'] ?? null);
        self::assertSame(0, $summary['dropped_span_count'] ?? null);
    }

    public function testInterleavedRequestFibersKeepIndependentDatabaseTotals(): void
    {
        $first = new \Fiber(function (): array {
            $this->enterRequest('aggregate-fiber-one');
            RequestLifecycleTrace::recordSpan('db::one', 1.0, 'db');
            \Fiber::suspend();
            RequestLifecycleTrace::recordSpan('db::three', 3.0, 'db');
            RequestLifecycleTrace::recordSpan('db::five', 5.0, 'db');
            return RequestLifecycleTrace::exportCompactPayload()['summary'];
        });
        $second = new \Fiber(function (): array {
            $this->enterRequest('aggregate-fiber-two');
            RequestLifecycleTrace::recordSpan('db::two', 2.0, 'db');
            return RequestLifecycleTrace::exportCompactPayload()['summary'];
        });

        $first->start();
        $second->start();
        $first->resume();
        self::assertSame(9.0, $first->getReturn()['db_duration_ms']);
        self::assertSame(3, $first->getReturn()['db_span_count'] ?? null);
        self::assertSame(2.0, $second->getReturn()['db_duration_ms']);
        self::assertSame(1, $second->getReturn()['db_span_count'] ?? null);
        self::assertSame(0, RequestLifecycleTrace::exportCompactPayload()['summary']['db_span_count'] ?? null);
    }

    public function testDisabledControlPlaneDoesNotAccumulateDatabaseTotals(): void
    {
        Context::leave();
        Context::enter(new Context([]));
        self::assertFalse(RequestLifecycleTrace::isEnabled());
        RequestLifecycleTrace::recordSpan('db::control_plane', 30.0, 'db');

        $summary = RequestLifecycleTrace::exportCompactPayload()['summary'];
        self::assertSame(0.0, $summary['db_duration_ms']);
        self::assertSame(0, $summary['db_span_count'] ?? null);
        self::assertSame(0, $summary['dropped_span_count'] ?? null);
    }

    public function testCompletedPhasesSurviveDetailCapWithBoundedNamesAndRequestReset(): void
    {
        self::assertSame([], RequestLifecycleTrace::getAggregateSummary()['phases'] ?? null);
        RequestLifecycleTrace::recordSpan('first_detail', 1.0);
        RequestLifecycleTrace::recordSpan('second_detail', 1.0);
        RequestLifecycleTrace::recordPhase('product.catalog.projection', 90.0, ['products' => 79]);
        for ($i = 0; $i < 47; ++$i) {
            RequestLifecycleTrace::recordPhase('phase.' . $i, 1.0);
        }
        RequestLifecycleTrace::recordPhase('over_phase_limit', 100.0);
        RequestLifecycleTrace::recordPhase('product.catalog.projection', 95.0, [
            'products' => 80,
            'sql' => "SELECT token_digest FROM remembered_credentials WHERE token_digest = 'secret'",
        ]);

        $payload = RequestLifecycleTrace::exportCompactPayload();
        $summary = $payload['summary'];
        self::assertCount(48, $summary['phases']);
        self::assertArrayNotHasKey('over_phase_limit', $summary['phases']);
        self::assertSame(95.0, $summary['phases']['product.catalog.projection']['duration_ms']);
        self::assertSame(80, $summary['phases']['product.catalog.projection']['meta']['products']);
        self::assertSame('[REDACTED: authentication persistence statement]', $summary['phases']['product.catalog.projection']['meta']['sql']);
        self::assertSame(50, $summary['dropped_span_count']);
        self::assertSame(0, $summary['db_span_count']);
        self::assertCount(2, RequestLifecycleTrace::getSpans());
        self::assertCount(2, $payload['dict']['names']);
        self::assertSame([], $payload['dict']['metas']);

        RequestLifecycleTrace::reset();
        self::assertSame([], RequestLifecycleTrace::getAggregateSummary()['phases']);
        RequestLifecycleTrace::recordPhase('next_request', 2.0);
        self::assertSame(['next_request'], array_keys(RequestLifecycleTrace::getAggregateSummary()['phases']));
        self::assertSame('phase', RequestLifecycleTrace::getSpans()[0]['category']);
    }

    public function testLateSlowSpanRemainsVisibleAfterChronologicalDetailCap(): void
    {
        RequestLifecycleTrace::recordSpan('first_detail', 1.0);
        RequestLifecycleTrace::recordSpan('second_detail', 2.0);
        RequestLifecycleTrace::recordSpan('late_parent', 500.0, 'view', 'render');
        RequestLifecycleTrace::recordSpan('db::fast_overflow', 0.004, 'db');
        RequestLifecycleTrace::recordSpan('wls::fast_overflow', 0.002, 'wls');

        self::assertSame(['first_detail', 'second_detail'], array_column(RequestLifecycleTrace::getSpans(), 'name'));
        $summary = RequestLifecycleTrace::getAggregateSummary();
        self::assertSame(2, $summary['span_count']);
        self::assertSame(3, $summary['dropped_span_count']);
        self::assertSame(1, $summary['db_span_count']);
        self::assertSame(1, $summary['wls_span_count']);
        self::assertCount(2, RequestLifecycleTrace::exportCompactPayload()['dict']['names']);

        $visible = array_column(RequestLifecycleTrace::getSpansWithDbSummary(), null, 'name');
        self::assertCount(3, $visible);
        self::assertArrayHasKey('late_parent', $visible);
        self::assertSame(500.0, $visible['late_parent']['duration_ms']);
        self::assertSame('view', $visible['late_parent']['category']);
        self::assertSame('render', $visible['late_parent']['parent']);
        self::assertArrayNotHasKey('db::fast_overflow', $visible);
        self::assertArrayNotHasKey('wls::fast_overflow', $visible);
    }

    public function testSlowOverflowSamplesStayBoundedAndKeepTheLargestDurations(): void
    {
        RequestLifecycleTrace::recordSpan('first_detail', 1.0);
        RequestLifecycleTrace::recordSpan('second_detail', 2.0);
        for ($i = 0; $i < 100; ++$i) {
            RequestLifecycleTrace::recordSpan('late_' . $i, 100.0 + $i, 'view');
        }

        self::assertCount(2, RequestLifecycleTrace::getSpans());
        self::assertCount(2, RequestLifecycleTrace::exportCompactPayload()['dict']['names']);
        self::assertSame(100, RequestLifecycleTrace::getAggregateSummary()['dropped_span_count']);
        $visible = RequestLifecycleTrace::getSpansWithDbSummary();
        self::assertCount(42, $visible);
        $durations = [];
        foreach ($visible as $span) {
            if (str_starts_with($span['name'], 'late_')) {
                $durations[] = $span['duration_ms'];
            }
        }
        sort($durations);
        self::assertSame(array_map(static fn(int $value): float => (float)$value, range(160, 199)), $durations);
    }

    public function testSlowOverflowUsesTheSameMetadataSanitizerAndResetsWithTheRequest(): void
    {
        $previousMetaMax = Env::get('wls.debug.request_trace_meta_max_bytes', 0);
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace_meta_max_bytes' => 32]]]);
        $meta = [
            'sql' => "SELECT token_digest FROM remembered_credentials WHERE token_digest = 'secret'",
            'detail' => str_repeat('long diagnostic detail ', 8),
        ];
        try {
            RequestLifecycleTrace::recordSpan('early_metadata', 100.0, 'view', null, $meta);
            RequestLifecycleTrace::recordSpan('second_detail', 1.0);
            RequestLifecycleTrace::recordSpan('late_metadata', 500.0, 'view', null, $meta);
            $visible = array_column(RequestLifecycleTrace::getSpansWithDbSummary(), null, 'name');
            self::assertArrayHasKey('late_metadata', $visible);
            self::assertSame($visible['early_metadata']['meta'], $visible['late_metadata']['meta']);
            self::assertSame('[REDACTED: authentication persistence statement]', $visible['late_metadata']['meta']['sql']);
            self::assertLessThan(strlen($meta['detail']), strlen($visible['late_metadata']['meta']['detail']));

            RequestLifecycleTrace::reset();
            self::assertSame([], RequestLifecycleTrace::getSpans());
            self::assertSame([], RequestLifecycleTrace::getSpansWithDbSummary());
            self::assertSame(0, RequestLifecycleTrace::getAggregateSummary()['dropped_span_count']);
            Context::leave();
            $this->enterRequest('slow-sample-next');
            self::assertSame([], RequestLifecycleTrace::getSpansWithDbSummary());
        } finally {
            Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace_meta_max_bytes' => $previousMetaMax]]]);
        }
    }

    public function testInterleavedRequestFibersKeepIndependentSlowOverflowSamples(): void
    {
        $first = new \Fiber(function (): array {
            $this->enterRequest('slow-sample-fiber-one');
            RequestLifecycleTrace::recordSpan('one_first', 1.0);
            RequestLifecycleTrace::recordSpan('one_second', 1.0);
            RequestLifecycleTrace::recordSpan('one_late', 500.0);
            \Fiber::suspend();
            return array_column(RequestLifecycleTrace::getSpansWithDbSummary(), 'name');
        });
        $second = new \Fiber(function (): array {
            $this->enterRequest('slow-sample-fiber-two');
            RequestLifecycleTrace::recordSpan('two_first', 1.0);
            RequestLifecycleTrace::recordSpan('two_second', 1.0);
            RequestLifecycleTrace::recordSpan('two_late', 600.0);
            return array_column(RequestLifecycleTrace::getSpansWithDbSummary(), 'name');
        });
        $first->start();
        $second->start();
        $first->resume();
        self::assertSame(['one_first', 'one_second', 'one_late'], $first->getReturn());
        self::assertSame(['two_first', 'two_second', 'two_late'], $second->getReturn());
        self::assertSame([], RequestLifecycleTrace::getSpansWithDbSummary());
    }

    public function testMeasuredPhasesAccumulateCallsButRecordIndividualSpans(): void
    {
        $firstValue = new \stdClass();
        $calls = 0;
        self::assertSame($firstValue, RequestLifecycleTrace::measurePhase('catalog.resolve', function () use (&$calls, $firstValue): object {
            ++$calls;
            usleep(1500);
            return $firstValue;
        }));
        self::assertFalse(RequestLifecycleTrace::measurePhase('catalog.resolve', function () use (&$calls): bool {
            ++$calls;
            usleep(3000);
            return false;
        }, ['sql' => "SELECT token_digest FROM remembered_credentials WHERE token_digest = 'secret'"]));

        $phase = RequestLifecycleTrace::getAggregateSummary()['phases']['catalog.resolve'];
        $spans = RequestLifecycleTrace::getSpans();
        self::assertSame(2, $calls);
        self::assertSame(2, $phase['calls']);
        self::assertSame(0, $phase['errors']);
        self::assertSame('inclusive', $phase['measurement']);
        self::assertSame(0, $phase['db_span_count']);
        self::assertSame(0, $phase['wls_span_count']);
        self::assertCount(2, $spans);
        self::assertSame(['catalog.resolve', 'catalog.resolve'], array_column($spans, 'name'));
        self::assertSame(['phase', 'phase'], array_column($spans, 'category'));
        $durations = array_column($spans, 'duration_ms');
        self::assertGreaterThan(0.0, min($durations));
        self::assertEqualsWithDelta(array_sum($durations), $phase['duration_ms'], 0.02);
        self::assertEqualsWithDelta(max($durations), $phase['max_ms'], 0.01);
        self::assertSame('[REDACTED: authentication persistence statement]', $phase['meta']['sql']);
    }

    public function testMeasuredPhaseRethrowsTheSameExceptionAndRecordsFailure(): void
    {
        $failure = new \RuntimeException('callback failure');
        try {
            RequestLifecycleTrace::measurePhase('catalog.failed', static function () use ($failure): never {
                throw $failure;
            });
            self::fail('The callback exception must be rethrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $phase = RequestLifecycleTrace::getAggregateSummary()['phases']['catalog.failed'];
        self::assertSame(1, $phase['calls']);
        self::assertSame(1, $phase['errors']);
        self::assertSame('inclusive', $phase['measurement']);
        self::assertCount(1, RequestLifecycleTrace::getSpans());
    }

    public function testMeasuredPhaseTotalsSurviveCapsAndRemainRequestLocal(): void
    {
        RequestLifecycleTrace::recordSpan('db::before_phase', 5.0, 'db');
        RequestLifecycleTrace::recordSpan('wls::before_phase', 3.0, 'wls');
        RequestLifecycleTrace::measurePhase('catalog.io', static function (): void {
            RequestLifecycleTrace::recordSpan('db::inside_one', 1.25, 'db');
            RequestLifecycleTrace::recordSpan('db::inside_two', 2.25, 'db');
            RequestLifecycleTrace::recordSpan('wls.memory.cache_get', 7.0, 'wls');
        });

        $summary = RequestLifecycleTrace::getAggregateSummary();
        $phase = $summary['phases']['catalog.io'];
        self::assertTrue($summary['truncated']);
        self::assertSame(2, $summary['span_count']);
        self::assertSame(4, $summary['dropped_span_count']);
        self::assertSame(3, $summary['db_span_count']);
        self::assertSame(8.5, $summary['db_duration_ms']);
        self::assertSame(2, $summary['wls_span_count']);
        self::assertSame(10.0, $summary['wls_duration_ms']);
        self::assertSame(2, $phase['db_span_count']);
        self::assertSame(3.5, $phase['db_duration_ms']);
        self::assertSame(1, $phase['wls_span_count']);
        self::assertSame(7.0, $phase['wls_duration_ms']);
        self::assertSame(1, $phase['calls']);
        for ($i = 0; $i < 47; ++$i) {
            RequestLifecycleTrace::recordPhase('bounded.' . $i, 1.0);
        }
        self::assertSame('executed', RequestLifecycleTrace::measurePhase('beyond_phase_limit', static fn(): string => 'executed'));
        $summary = RequestLifecycleTrace::getAggregateSummary();
        self::assertCount(48, $summary['phases']);
        self::assertArrayNotHasKey('beyond_phase_limit', $summary['phases']);

        RequestLifecycleTrace::reset();
        $summary = RequestLifecycleTrace::getAggregateSummary();
        self::assertSame([], $summary['phases']);
        self::assertSame(0, $summary['db_span_count']);
        self::assertSame(0, $summary['wls_span_count']);
        self::assertSame(0.0, $summary['wls_duration_ms']);
    }

    public function testReserveSummaryPhasesStayUpdatableAfterCap(): void
    {
        RequestLifecycleTrace::reserveSummaryPhases(['pdp.main', 'pdp.personalization', 'theme.layout.L3-slots']);
        for ($i = 0; $i < 60; ++$i) {
            RequestLifecycleTrace::recordPhase('filler.' . $i, 1.0);
        }
        RequestLifecycleTrace::measurePhase('pdp.main', static function (): string {
            $x = 0;
            for ($i = 0; $i < 5000; $i++) {
                $x += $i;
            }

            return 'ok-' . $x;
        });
        $phases = RequestLifecycleTrace::getAggregateSummary()['phases'];
        self::assertArrayHasKey('pdp.main', $phases);
        self::assertArrayHasKey('pdp.personalization', $phases);
        self::assertGreaterThanOrEqual(1, (int)$phases['pdp.main']['calls']);
        self::assertSame(0, (int)($phases['pdp.personalization']['calls'] ?? 0));
    }

    public function testFiberScopedAggregatesRemainIsolated(): void
    {
        $first = new \Fiber(function (): array {
            $this->enterRequest('measured-fiber-one');
            RequestLifecycleTrace::measurePhase('shared.name', static function (): void {
                RequestLifecycleTrace::recordSpan('db::one', 1.0, 'db');
                RequestLifecycleTrace::recordSpan('wls::one', 2.0, 'wls');
                \Fiber::suspend();
                RequestLifecycleTrace::recordSpan('db::three', 3.0, 'db');
                RequestLifecycleTrace::recordSpan('wls::three', 4.0, 'wls');
            });
            return RequestLifecycleTrace::getAggregateSummary()['phases']['shared.name'];
        });
        $second = new \Fiber(function (): array {
            $this->enterRequest('measured-fiber-two');
            RequestLifecycleTrace::measurePhase('shared.name', static function (): void {
                RequestLifecycleTrace::recordSpan('db::two', 8.0, 'db');
                RequestLifecycleTrace::recordSpan('wls::two', 16.0, 'wls');
            });
            return RequestLifecycleTrace::getAggregateSummary()['phases']['shared.name'];
        });
        $first->start();
        $second->start();
        $first->resume();
        self::assertSame(1, $first->getReturn()['calls']);
        self::assertSame(2, $first->getReturn()['db_span_count']);
        self::assertSame(4.0, $first->getReturn()['db_duration_ms']);
        self::assertSame(2, $first->getReturn()['wls_span_count']);
        self::assertSame(6.0, $first->getReturn()['wls_duration_ms']);
        self::assertSame(1, $second->getReturn()['db_span_count']);
        self::assertSame(8.0, $second->getReturn()['db_duration_ms']);
        self::assertSame(1, $second->getReturn()['wls_span_count']);
        self::assertSame(16.0, $second->getReturn()['wls_duration_ms']);
        self::assertSame([], RequestLifecycleTrace::getAggregateSummary()['phases']);
        self::assertSame(0, RequestLifecycleTrace::getAggregateSummary()['wls_span_count']);
    }

    public function testDisabledMeasurementStillExecutesCallbackWithoutRecording(): void
    {
        Context::leave();
        Context::enter(new Context([]));
        self::assertFalse(RequestLifecycleTrace::isEnabled());
        $calls = 0;
        self::assertSame('uncached', RequestLifecycleTrace::measurePhase('control-plane', static function () use (&$calls): string {
            ++$calls;
            RequestLifecycleTrace::recordSpan('wls::disabled', 12.0, 'wls');
            return 'uncached';
        }));
        self::assertSame(1, $calls);
        $summary = RequestLifecycleTrace::getAggregateSummary();
        self::assertSame([], $summary['phases']);
        self::assertSame(0, $summary['span_count']);
        self::assertSame(0, $summary['db_span_count']);
        self::assertSame(0, $summary['wls_span_count']);
        self::assertSame(0.0, $summary['wls_duration_ms']);
    }

    private function enterRequest(string $requestId): void
    {
        Context::enter(new Context([
            'input' => ['uri' => '/categories'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => $requestId]],
        ]));
        self::assertTrue(RequestContext::isInitialized());
    }
}
