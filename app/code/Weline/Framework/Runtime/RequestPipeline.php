<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App;
use Weline\Framework\Http\Response;

/**
 * One application request pipeline shared by FPM and WLS.
 *
 * Context establishment and Worker transport policy/static/FPC L1 happen
 * outside this class. The fixed application stages are:
 *
 * bootstrap -> pre-route gate -> URL/apply -> early response -> before
 * -> lazy session -> router/controller -> after
 *
 * `after` is a normal routed-response event. Early responses and failures do
 * not invoke it; Runtime finally + RequestResetter provide exactly-once cleanup
 * for every exit path.
 */
final class RequestPipeline implements RequestPipelineInterface
{
    public const EARLY_RESPONSE_CONTEXT_KEY = 'request.pipeline.early_response';
    public const LEGACY_WLS_EARLY_RESPONSE_CONTEXT_KEY = 'wls.fpc.cached_response';
    public const STAGE_URL = 'url';
    public const STAGE_ROUTE = 'route';

    public function __construct(
        private readonly ?RequestPipelineStageListenerInterface $stageListener = null,
    ) {
    }

    public static function registerEarlyResponse(Response $response): void
    {
        RequestContext::set(self::EARLY_RESPONSE_CONTEXT_KEY, $response);
        // One-release compatibility bridge for existing WLS diagnostics and
        // extensions that still inspect the old request-scoped key.
        RequestContext::set(self::LEGACY_WLS_EARLY_RESPONSE_CONTEXT_KEY, $response);
    }

    public static function earlyResponse(): ?Response
    {
        $response = RequestContext::get(self::EARLY_RESPONSE_CONTEXT_KEY);
        if (!$response instanceof Response) {
            $response = RequestContext::get(self::LEGACY_WLS_EARLY_RESPONSE_CONTEXT_KEY);
        }

        return $response instanceof Response ? $response : null;
    }

    public static function clearEarlyResponse(): void
    {
        RequestContext::set(self::EARLY_RESPONSE_CONTEXT_KEY, null);
        RequestContext::set(self::LEGACY_WLS_EARLY_RESPONSE_CONTEXT_KEY, null);
    }

    public function execute(
        App $app,
        bool $bootstrapRequestCycle = true,
        bool $startSession = true,
    ): RequestPipelineResult {
        // Stage timings always run: microtime() costs ~0.1 µs per sample (under
        // 1 µs for the whole pipeline), but with the trace panel closed every
        // stage read 0 — the pipeline became unmeasurable exactly when someone
        // was investigating a slow request. TraceGate span recording stays behind
        // the flag, so the closed panel still avoids the recorder load.
        $traceEnabled = TraceGate::isArmed();
        $timings = [];

        if ($bootstrapRequestCycle) {
            $startedAt = \microtime(true);
            try {
                $app->bootstrapRequestCycle();
            } finally {
                $timings['bootstrap_ms'] = self::elapsedMilliseconds($startedAt);
            }
        }

        $startedAt = \microtime(true);
        if ($traceEnabled) {
            TraceGate::pushCurrentParent('pre_route_gate');
        }
        try {
            $app->dispatchPreRouteGate();
        } finally {
            $timings['pre_route_gate_ms'] = self::elapsedMilliseconds($startedAt);
            if ($traceEnabled) {
                TraceGate::popCurrentParent();
                TraceGate::recordSpan('pre_route_gate', $timings['pre_route_gate_ms'], 'framework');
            }
        }

        $urlStartedAt = \microtime(true);
        $parseStartedAt = \microtime(true);
        try {
            $parsedUrl = $app->parseUrl();
        } finally {
            $timings['url_parser_call_ms'] = self::elapsedMilliseconds($parseStartedAt);
            if ($traceEnabled) {
                TraceGate::recordSpan(
                    'url_parser::parse',
                    $timings['url_parser_call_ms'],
                    'framework',
                    'url_parser'
                );
            }
        }
        if (\is_array($parsedUrl)) {
            $applyStartedAt = \microtime(true);
            try {
                $app->applyParsedUrl($parsedUrl);
            } finally {
                $timings['process_url_parse_ms'] = self::elapsedMilliseconds($applyStartedAt);
                if ($traceEnabled) {
                    TraceGate::recordSpan(
                        'url_parser::apply',
                        $timings['process_url_parse_ms'],
                        'framework',
                        'url_parser'
                    );
                }
            }
        }
        $timings['url_parser_ms'] = self::elapsedMilliseconds($urlStartedAt);
        if ($traceEnabled) {
            TraceGate::recordSpan('url_parser', $timings['url_parser_ms'], 'framework');
        }
        $this->notifyStage(self::STAGE_URL, $timings['url_parser_ms']);

        $cachedResponse = self::earlyResponse();
        if ($cachedResponse instanceof Response) {
            return new RequestPipelineResult(
                result: '',
                earlyResponse: $cachedResponse,
                parsedUrl: \is_array($parsedUrl) ? $parsedUrl : [],
                timings: $timings,
            );
        }

        $startedAt = \microtime(true);
        if ($traceEnabled) {
            TraceGate::pushCurrentParent('run_before');
        }
        try {
            $app->dispatchRunBefore();
        } finally {
            $timings['run_before_ms'] = self::elapsedMilliseconds($startedAt);
            if ($traceEnabled) {
                TraceGate::popCurrentParent();
                TraceGate::recordSpan('run_before', $timings['run_before_ms'], 'framework');
            }
        }

        if ($startSession) {
            $startedAt = \microtime(true);
            try {
                $app->startSessionIfNeeded();
            } finally {
                $timings['session_start_ms'] = self::elapsedMilliseconds($startedAt);
                if ($traceEnabled) {
                    TraceGate::recordSpan(
                        'router_start::session',
                        $timings['session_start_ms'],
                        'framework',
                        'router_start'
                    );
                }
            }
        } else {
            // 阶段被主动跳过（如内部首页 FPC 预热不启会话）时，仍要产出计时键。
            // $timings 是**固定键集合的契约**，下游 reader（WlsRuntime 汇总
            // router_start_ms）不逐个判键；缺键会掉成 Undefined array key 警告。
            $timings['session_start_ms'] = 0.0;
        }

        $routerPhaseStartedAt = \microtime(true);
        $startedAt = \microtime(true);
        try {
            $router = $app->initializeRouter();
        } finally {
            $timings['router_init_ms'] = self::elapsedMilliseconds($startedAt);
            if ($traceEnabled) {
                TraceGate::recordSpan('router_init', $timings['router_init_ms'], 'framework');
                TraceGate::pushCurrentParent('router_start');
            }
        }
        $startedAt = \microtime(true);
        try {
            $result = $app->runRouter($router);
        } finally {
            $timings['router_start_call_ms'] = self::elapsedMilliseconds($startedAt);
            $timings['router_start_ms'] = self::elapsedMilliseconds($routerPhaseStartedAt);
            if ($traceEnabled) {
                TraceGate::popCurrentParent();
                TraceGate::recordSpan(
                    'router_start::dispatch',
                    $timings['router_start_call_ms'],
                    'framework',
                    'router_start'
                );
                TraceGate::recordSpan('router_start', $timings['router_start_ms'], 'framework');
            }
            $this->notifyStage(self::STAGE_ROUTE, $timings['router_start_ms']);
        }
        unset($router);

        $startedAt = \microtime(true);
        if ($traceEnabled) {
            TraceGate::pushCurrentParent('run_after');
        }
        try {
            $result = $app->dispatchRunAfter($result);
        } finally {
            $timings['run_after_ms'] = self::elapsedMilliseconds($startedAt);
            if ($traceEnabled) {
                TraceGate::popCurrentParent();
                TraceGate::recordSpan('run_after', $timings['run_after_ms'], 'framework');
            }
        }

        return new RequestPipelineResult(
            result: $result,
            earlyResponse: null,
            parsedUrl: \is_array($parsedUrl) ? $parsedUrl : [],
            timings: $timings,
        );
    }

    private function notifyStage(string $stage, float $elapsedMilliseconds): void
    {
        if (!$this->stageListener instanceof RequestPipelineStageListenerInterface) {
            return;
        }

        try {
            $this->stageListener->afterRequestPipelineStage($stage, $elapsedMilliseconds);
        } catch (\Throwable) {
            // Runtime maintenance/compaction hooks must never change the
            // response or hide the original application exception.
        }
    }

    private static function elapsedMilliseconds(float $startedAt): float
    {
        return \round((\microtime(true) - $startedAt) * 1000, 2);
    }
}
