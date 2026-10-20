<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Fpc\FpcPierceStamp;

/**
 * Request-scoped render-route breadcrumbs (diagnostic only).
 *
 * Gate: query `wls_tpl_route=1|true` + install FPC pierce stamp (same storm model as
 * `wls_tpl_perf`), or RequestContext `view.template.route_trace=true`.
 *
 * Owning: Weline_Framework. Does not alter render semantics; never soft-dedupe widgets.
 */
final class TemplateRenderRouteTrace
{
    private const CTX_EVENTS = 'view.template.route_trace.events';
    private const CTX_DEPTH = 'view.template.route_trace.depth';
    private const CTX_COUNTS = 'view.template.route_trace.counts';
    private const CTX_ARMED = 'view.template.route_trace';
    private const CTX_REQUESTED_CACHE = 'view.template.route_trace.requested_cache';
    private const PANEL_ID = 'wls-tpl-route';
    private const JSON_ID = 'wls-tpl-route-json';

    /**
     * Per-scope "is the route trace requested" latch.
     *
     * Keyed by Fiber/Context rather than held in one process-wide static: a bare
     * static `true` survived into the next request on a long-lived WLS worker, so one
     * `wls_tpl_route=1` request turned tracing on for everything after it. The latch
     * also caches `false`, which is what makes the disarmed hot path a WeakMap read
     * instead of two `RequestContext::get()` calls per `begin()`/`end()`.
     *
     * @var \WeakMap<\Fiber, bool>|null
     */
    private static ?\WeakMap $fiberMemo = null;

    /** @var \WeakMap<Context, bool>|null */
    private static ?\WeakMap $contextMemo = null;

    private static ?bool $mainMemo = null;

    public static function clearMemo(): void
    {
        self::$fiberMemo = null;
        self::$contextMemo = null;
        self::$mainMemo = null;
    }

    public static function isRequested(): bool
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        $context = $fiber === null ? Context::getCurrent() : null;

        if ($fiber !== null) {
            self::$fiberMemo ??= new \WeakMap();
            $memo = self::$fiberMemo[$fiber] ?? null;
        } elseif ($context !== null) {
            self::$contextMemo ??= new \WeakMap();
            $memo = self::$contextMemo[$context] ?? null;
        } else {
            $memo = self::$mainMemo;
        }

        if ($memo !== null) {
            return $memo;
        }

        $memo = self::resolveRequested();

        if ($fiber !== null) {
            self::$fiberMemo[$fiber] = $memo;
        } elseif ($context !== null) {
            self::$contextMemo[$context] = $memo;
        } else {
            self::$mainMemo = $memo;
        }

        return $memo;
    }

    private static function resolveRequested(): bool
    {
        try {
            if (RequestContext::isInitialized() && RequestContext::get(self::CTX_ARMED) === true) {
                return true;
            }
            if (RequestContext::isInitialized() && RequestContext::get(self::CTX_REQUESTED_CACHE) === true) {
                return true;
            }
            if (RequestContext::isInitialized() && RequestContext::get(self::CTX_REQUESTED_CACHE) === false) {
                return false;
            }
        } catch (\Throwable) {
        }

        $flag = self::readRouteFlag();
        if ($flag === null) {
            self::cacheRequested(false);

            return false;
        }
        if ($flag !== '1' && $flag !== 'true') {
            self::cacheRequested(false);

            return false;
        }

        $ok = FpcPierceStamp::isValidCurrentRequest();
        self::cacheRequested($ok);

        return $ok;
    }

    public static function armIfRequested(): void
    {
        if (!self::isRequested()) {
            return;
        }
        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set(self::CTX_ARMED, true);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, scalar|null> $meta
     */
    public static function mark(string $kind, string $identity, array $meta = []): void
    {
        if (!self::isRequested()) {
            return;
        }
        self::armIfRequested();
        if (!RequestContext::isInitialized()) {
            return;
        }

        $kind = \trim($kind);
        $identity = \trim($identity);
        if ($kind === '' || $identity === '') {
            return;
        }

        $key = $kind . '|' . $identity;
        $counts = RequestContext::get(self::CTX_COUNTS);
        if (!\is_array($counts)) {
            $counts = [];
        }
        $hit = (int)($counts[$key] ?? 0) + 1;
        $counts[$key] = $hit;
        RequestContext::set(self::CTX_COUNTS, $counts);

        $events = RequestContext::get(self::CTX_EVENTS);
        if (!\is_array($events)) {
            $events = [];
        }
        $depth = (int)(RequestContext::get(self::CTX_DEPTH) ?? 0);
        $events[] = [
            'seq' => \count($events) + 1,
            'depth' => \max(0, $depth),
            'kind' => $kind,
            'identity' => $identity,
            'dup' => $hit > 1,
            'hit' => $hit,
            'meta' => self::sanitizeMeta($meta),
        ];
        RequestContext::set(self::CTX_EVENTS, $events);
    }

    /**
     * Enter a nested render span. Marks once (begin only) so begin/end pairs
     * do not inflate duplicate-identity hits.
     *
     * @param array<string, scalar|null> $meta
     */
    public static function begin(string $kind, string $identity, array $meta = []): void
    {
        if (!self::isRequested() || !RequestContext::isInitialized()) {
            return;
        }
        $depth = (int)(RequestContext::get(self::CTX_DEPTH) ?? 0);
        RequestContext::set(self::CTX_DEPTH, $depth + 1);
        self::mark($kind, $identity, $meta);
    }

    /**
     * Leave a nested render span without a second mark (depth only).
     */
    public static function end(string $kind = '', string $identity = '', array $meta = []): void
    {
        if (!self::isRequested() || !RequestContext::isInitialized()) {
            return;
        }
        $depth = (int)(RequestContext::get(self::CTX_DEPTH) ?? 0);
        RequestContext::set(self::CTX_DEPTH, \max(0, $depth - 1));
    }

    /**
     * @return list<array{seq:int,depth:int,kind:string,identity:string,dup:bool,hit:int,meta:array<string,scalar|null>}>
     */
    public static function events(): array
    {
        if (!RequestContext::isInitialized()) {
            return [];
        }
        $events = RequestContext::get(self::CTX_EVENTS);

        return \is_array($events) ? $events : [];
    }

    public static function appendToHtml(string $html): string
    {
        if ($html === '' || !self::isRequested()) {
            return $html;
        }
        if (\str_contains($html, 'id="' . self::PANEL_ID . '"') || \str_contains($html, "id='" . self::PANEL_ID . "'")) {
            return $html;
        }

        $events = self::events();
        $payload = [
            'version' => 1,
            'event_count' => \count($events),
            'dup_count' => \count(\array_filter($events, static fn(array $e): bool => !empty($e['dup']))),
            'events' => $events,
        ];
        $json = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            $json = '{"version":1,"event_count":0,"dup_count":0,"events":[]}';
        }

        $rows = '';
        foreach ($events as $event) {
            $indent = \str_repeat('· ', \max(0, (int)$event['depth']));
            $tone = !empty($event['dup']) ? '#b91c1c' : '#0f172a';
            $dup = !empty($event['dup']) ? ' DUP×' . (int)$event['hit'] : '';
            $rows .= \sprintf(
                '<div style="color:%s;padding:1px 0;border-bottom:1px solid #e2e8f0;">%s<span style="opacity:.65">#%d</span> <b>%s</b> %s%s</div>',
                $tone,
                \htmlspecialchars($indent, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                (int)$event['seq'],
                \htmlspecialchars((string)$event['kind'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                \htmlspecialchars((string)$event['identity'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                \htmlspecialchars($dup, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
            );
        }
        if ($rows === '') {
            $rows = '<div style="opacity:.7">（本请求尚无 mark；确认 pierce 与冷路径是否走到 Template）</div>';
        }

        $panel = \sprintf(
            '<aside id="%s" data-wls-tpl-route="1" style="position:fixed;right:12px;bottom:12px;z-index:2147483001;width:min(42rem,calc(100vw - 24px));max-height:45vh;overflow:auto;background:#fff;color:#0f172a;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 28px rgba(15,23,42,.22);font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;padding:10px 12px;">'
            . '<div style="font-weight:700;margin:0 0 6px;color:#0f172a;">wls_tpl_route · %d steps · dup %d</div>%s</aside>'
            . '<script type="application/json" id="%s">%s</script>',
            self::PANEL_ID,
            \count($events),
            (int)$payload['dup_count'],
            $rows,
            self::JSON_ID,
            \htmlspecialchars($json, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
        );

        $comment = '<!-- wls-tpl-route events=' . \count($events)
            . ' dup=' . (int)$payload['dup_count'] . ' -->';

        if (\preg_match('/<\/body\s*>/i', $html, $m, \PREG_OFFSET_CAPTURE) === 1) {
            $at = (int)$m[0][1];

            return \substr($html, 0, $at) . $comment . $panel . \substr($html, $at);
        }

        return $comment . $panel . $html;
    }

    private static function cacheRequested(bool $value): void
    {
        try {
            if (RequestContext::isInitialized()) {
                RequestContext::set(self::CTX_REQUESTED_CACHE, $value);
            }
        } catch (\Throwable) {
        }
    }

    private static function readRouteFlag(): ?string
    {
        try {
            if (RequestContext::isInitialized()
                && \class_exists(\Weline\Framework\Manager\ObjectManager::class, false)
            ) {
                /** @var \Weline\Framework\Http\Request $request */
                $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
                $flag = (string)($request->getGet('wls_tpl_route') ?? $request->getParam('wls_tpl_route') ?? '');
                if ($flag !== '') {
                    return \strtolower($flag);
                }
            }
        } catch (\Throwable) {
        }

        $query = (string)WelineEnv::server('QUERY_STRING', '');
        if ($query === '') {
            $requestUri = (string)WelineEnv::server('REQUEST_URI', '');
            if ($requestUri === '' && \function_exists('w_env_request_uri')) {
                $requestUri = (string)\w_env_request_uri();
            }
            $parts = \parse_url($requestUri);
            $query = \is_array($parts) ? (string)($parts['query'] ?? '') : '';
        }
        if ($query === '') {
            return null;
        }
        \parse_str($query, $params);
        $flag = $params['wls_tpl_route'] ?? null;
        if (\is_array($flag) || $flag === null || $flag === '') {
            return null;
        }

        return \strtolower((string)$flag);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, scalar|null>
     */
    private static function sanitizeMeta(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $value) {
            if (!\is_string($key) || $key === '') {
                continue;
            }
            if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (\is_string($value)) {
                $out[$key] = \mb_strlen($value) > 240 ? \mb_substr($value, 0, 240) . '…' : $value;
            }
        }

        return $out;
    }
}
