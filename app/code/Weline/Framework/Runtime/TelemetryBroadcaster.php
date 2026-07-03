<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * 请求遥测广播器（Framework 层）
 *
 * 职责：
 * 1. 读取 RequestLifecycleTrace 的 spans
 * 2. 组装 request/runtime/summary/result 快照
 * 3. 通过 Framework 事件统一广播给上层模块监听
 *
 * 说明：Framework 仅广播通用数据，不依赖具体消费模块（如 DeveloperWorkspace）。
 */
class TelemetryBroadcaster
{
    private const REQUEST_COLLECTED_EVENT = 'Weline_Framework::telemetry::request_collected';

    private const TRACE_MODE_OFF = 'off';

    private const TRACE_MODE_SAMPLED = 'sampled';

    private const TRACE_MODE_FULL = 'full';

    private static int $sampleCounter = 0;

    /**
     * 广播请求遥测事件。
     *
     * @param string $result 当前响应字符串（监听者可修改）
     * @param Request|null $request 可选 Request（传 null 时尝试从容器获取）
     * @return string 广播后（可能被观察者修改）的响应字符串
     */
    public static function broadcast(string $result, ?Request $request = null, bool $forceResultMutation = false): string
    {
        $request ??= self::resolveRequest();
        $hasDevToolContext = self::hasDevToolActivation($request) || self::containsDevToolMarkup($result);
        $allowResultMutation = $forceResultMutation
            || !(bool)\w_env('response.from_cache', false)
            || self::allowsDebugResponseDecoration()
            || $hasDevToolContext;
        if (!self::shouldBroadcast($forceResultMutation || $hasDevToolContext)) {
            return $result;
        }

        // 遥测事件始终派发：即便未启用 RequestLifecycleTrace，监听者也可以拿到 request/runtime/result 等信息
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        if (!$eventsManager->hasObservers(self::REQUEST_COLLECTED_EVENT)) {
            return $result;
        }

        $spans = RequestLifecycleTrace::isEnabled()
            ? RequestLifecycleTrace::getSpansWithDbSummary()
            : [];

        $requestSnapshot = self::buildRequestSnapshot($request);
        $runtimeSnapshot = [
            'mode' => Runtime::getMode(),
            'timestamp' => microtime(true),
        ];
        $summary = self::buildSummary($spans);
        $extensions = self::buildExtensions($spans);

        $eventData = [
            'data' => [
                'request' => $requestSnapshot,
                'runtime' => $runtimeSnapshot,
                'trace' => ['spans' => $spans],
                'summary' => $summary,
                'extensions' => $extensions,
                'result' => $result,
            ],
        ];

        $eventsManager->dispatch(self::REQUEST_COLLECTED_EVENT, $eventData);

        if (!$allowResultMutation) {
            return $result;
        }

        return (string)($eventData['data']['result'] ?? $result);
    }

    private static function allowsDebugResponseDecoration(): bool
    {
        return (\defined('DEV') && DEV)
            || (\defined('DEBUG') && DEBUG)
            || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE);
    }

    private static function hasDevToolActivation(?Request $request): bool
    {
        try {
            return ObjectManager::getInstance(PanelAccessService::class)->canAccessPanel($request);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function containsDevToolMarkup(string $result): bool
    {
        return \stripos($result, 'id="dev-tool-panel"') !== false
            || \stripos($result, 'window.__WELINE_REQUEST_ID__=') !== false;
    }

    public static function shouldBroadcast(bool $forceResultMutation = false): bool
    {
        if ($forceResultMutation) {
            return true;
        }

        $mode = self::resolveTraceMode();
        if ($mode === self::TRACE_MODE_OFF) {
            return false;
        }

        if ($mode === self::TRACE_MODE_FULL) {
            return true;
        }

        return self::shouldSampleCurrentRequest();
    }

    private static function resolveTraceMode(): string
    {
        $defaultMode = (\defined('PROD') && PROD) ? self::TRACE_MODE_SAMPLED : self::TRACE_MODE_FULL;
        $configuredMode = \strtolower(\trim((string)Env::get('performance.telemetry.mode', $defaultMode)));

        return match ($configuredMode) {
            '0', 'false', 'disabled', self::TRACE_MODE_OFF => self::TRACE_MODE_OFF,
            '1', 'true', 'enabled', 'on', self::TRACE_MODE_FULL => self::TRACE_MODE_FULL,
            default => self::TRACE_MODE_SAMPLED,
        };
    }

    private static function shouldSampleCurrentRequest(): bool
    {
        $rate = (int)Env::get('performance.telemetry.sample_rate', 100);
        if ($rate <= 1) {
            return true;
        }

        self::$sampleCounter = (self::$sampleCounter % $rate) + 1;
        return self::$sampleCounter === 1;
    }

    private static function resolveRequest(): ?Request
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function buildRequestSnapshot(?Request $request): array
    {
        if (!$request) {
            return [
                'uri' => (string)WelineEnv::server('REQUEST_URI', ''),
                'method' => (string)WelineEnv::server('REQUEST_METHOD', ''),
                'is_backend' => false,
                'is_api_backend' => false,
                'is_api_frontend' => false,
                'is_ajax' => false,
                'is_iframe' => false,
            ];
        }

        return [
            'uri' => (string)$request->getUri(),
            'method' => (string)$request->getMethod(),
            'is_backend' => (bool)$request->isBackend(),
            'is_api_backend' => (bool)$request->isApiBackend(),
            'is_api_frontend' => (bool)$request->isApiFrontend(),
            'is_ajax' => (bool)$request->isAjax(),
            'is_iframe' => (bool)$request->isIframe(),
        ];
    }

    /**
     * @param array<int, array{name?:string,duration_ms?:float|int,category?:string}> $spans
     */
    private static function buildSummary(array $spans): array
    {
        $total = 0.0;
        $dbTotal = 0.0;
        $categoryTotals = [];
        foreach ($spans as $span) {
            $duration = (float)($span['duration_ms'] ?? 0);
            $total += $duration;
            $category = (string)($span['category'] ?? 'framework');
            if (!isset($categoryTotals[$category])) {
                $categoryTotals[$category] = 0.0;
            }
            $categoryTotals[$category] += $duration;
            if ($category === 'db') {
                $dbTotal += $duration;
            }
        }
        foreach ($categoryTotals as $category => $duration) {
            $categoryTotals[$category] = round($duration, 2);
        }

        return [
            'total_duration_ms' => round($total, 2),
            'db_total_ms' => round($dbTotal, 2),
            'spans_total' => count($spans),
            'category_totals' => $categoryTotals,
        ];
    }

    /**
     * 预留扩展区：统一放置非核心生命周期信息，避免后续变更破坏主结构。
     *
     * @param array<int, array{name?:string,duration_ms?:float|int,category?:string,parent?:string}> $spans
     */
    private static function buildExtensions(array $spans): array
    {
        $httpTotal = 0.0;
        $cacheTotal = 0.0;
        foreach ($spans as $span) {
            $duration = (float)($span['duration_ms'] ?? 0.0);
            $category = (string)($span['category'] ?? '');
            if ($category === 'http') {
                $httpTotal += $duration;
            } elseif ($category === 'cache') {
                $cacheTotal += $duration;
            }
        }

        return [
            'errors' => [],
            'external_calls' => [
                'http_total_ms' => round($httpTotal, 2),
                'cache_total_ms' => round($cacheTotal, 2),
            ],
        ];
    }
}
