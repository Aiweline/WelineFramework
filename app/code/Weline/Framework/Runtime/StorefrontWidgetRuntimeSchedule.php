<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * N3 / F2：固化模板页级部件运行时编排（小步）。
 *
 * 布局 fetch 前：收集本页 inline widget specs → 一次 HotCache {@see \Weline\Framework\Cache\Service\StorefrontScopeHotCache::prefetchPolicy}
 * （经 Theme asset primer / 协议 MGET）→ 再串行 {@see \Weline\Widget\Taglib\Widget::renderRuntimeInline}。
 *
 * 禁区：
 * - 不是第二套 RequestContext 渲染权威袋（≠ {@see StorefrontRenderContext} / `storefront.render_context.v1`）
 * - 禁止假 getMultiple；只走既有 prefetchPolicy
 */
final class StorefrontWidgetRuntimeSchedule
{
    /** 请求闩：本请求是否已 flush 过页级 prefetch。仅编排书签，非渲染事实权威。 */
    public const LATCH_KEY = 'storefront.widget_runtime_schedule.primed.v1';

    /** 已扫描的编译模板路径指纹，避免同文件重复读盘。 */
    private const SEEN_FILES_KEY = 'storefront.widget_runtime_schedule.seen_files.v1';

    /**
     * 从 Taglib 编译产物中提取 renderRuntimeInline 规格（type/name/code/module）。
     *
     * @return list<array{type:string,name:string,code:string,module:string}>
     */
    public static function collectInlineSpecsFromPhp(string $php): array
    {
        if ($php === '' || !str_contains($php, 'renderRuntimeInline')) {
            return [];
        }

        $specs = [];
        $offset = 0;
        while (($call = strpos($php, 'renderRuntimeInline', $offset)) !== false) {
            $paren = strpos($php, '(', $call);
            if ($paren === false) {
                break;
            }
            $arrayStart = strpos($php, 'array', $paren);
            if ($arrayStart === false || $arrayStart > $paren + 48) {
                $offset = $call + 18;
                continue;
            }
            $open = strpos($php, '(', $arrayStart);
            if ($open === false) {
                $offset = $call + 18;
                continue;
            }
            $close = self::matchingParenEnd($php, $open);
            if ($close === null) {
                $offset = $call + 18;
                continue;
            }
            $body = substr($php, $open + 1, $close - $open - 1);
            $type = self::extractExportedString($body, 'type');
            $name = self::extractExportedString($body, 'name');
            $code = self::extractExportedString($body, 'code');
            $module = self::extractExportedString($body, 'module');
            if ($type !== '' && $name !== '') {
                $specs[] = [
                    'type' => $type,
                    'name' => $name,
                    'code' => $code !== '' ? $code : $name,
                    'module' => $module,
                ];
            }
            $offset = $close + 1;
        }

        return $specs;
    }

    /**
     * 布局 / 内容模板 fetch 前调用：先 HotCache/path 预取，再词典，最后扫编译产物做 asset。
     * 幂等：同请求多次调用会合并新模板扫描；prefetch 经 HotCache L1 去重。
     *
     * @return int 本次尝试预取的逻辑键数量（含 0）
     */
    public function primeBeforeLayoutFetch(Template $template, string ...$templateRefs): int
    {
        // path/channel/meta 不得依赖 getFetchFile（会触发 resolveThemeFile）。
        $t0 = \microtime(true);
        $hot = $this->delegateHotCachePagePrefetch($template, ...$templateRefs);
        $t1 = \microtime(true);
        $dict = $this->delegatePageDictionaryPrefetch($template, ...$templateRefs);
        $t2 = \microtime(true);
        $specs = $this->scanTemplateRefs($template, ...$templateRefs);
        $t3 = \microtime(true);
        $asset = $this->delegateAssetPrefetch($specs);
        $t4 = \microtime(true);
        $primed = $hot + $dict + $asset;

        // #region agent log
        try {
            $payload = [
                'sessionId' => '8f7f40',
                'runId' => 'cold-lag-pre',
                'hypothesisId' => 'D,E',
                'location' => 'StorefrontWidgetRuntimeSchedule.php:primeBeforeLayoutFetch',
                'message' => 'page prime breakdown',
                'data' => [
                    'refs' => \count($templateRefs),
                    'hot_keys' => $hot,
                    'dict_keys' => $dict,
                    'specs' => \count($specs),
                    'asset_keys' => $asset,
                    'hot_ms' => \round(($t1 - $t0) * 1000, 2),
                    'dict_ms' => \round(($t2 - $t1) * 1000, 2),
                    'scan_ms' => \round(($t3 - $t2) * 1000, 2),
                    'asset_ms' => \round(($t4 - $t3) * 1000, 2),
                    'total_ms' => \round(($t4 - $t0) * 1000, 2),
                    'request_id' => RequestContext::getId(),
                ],
                'timestamp' => (int)\round(\microtime(true) * 1000),
            ];
            @\file_put_contents(
                '/Users/weline/Project/Official/框架/.cursor/debug-8f7f40.log',
                \json_encode($payload, \JSON_UNESCAPED_UNICODE) . "\n",
                \FILE_APPEND | \LOCK_EX
            );
        } catch (\Throwable) {
        }
        // #endregion

        if (!RequestContext::has(self::LATCH_KEY)) {
            RequestContext::set(self::LATCH_KEY, true);
        }

        return $primed;
    }

    public function isPrimed(): bool
    {
        return RequestContext::get(self::LATCH_KEY) === true;
    }

    /**
     * @return list<array{type:string,name:string,code:string,module:string}>
     */
    private function scanTemplateRefs(Template $template, string ...$templateRefs): array
    {
        $seen = RequestContext::get(self::SEEN_FILES_KEY);
        if (!\is_array($seen)) {
            $seen = [];
        }

        $specs = [];
        foreach ($templateRefs as $ref) {
            $ref = trim($ref);
            if ($ref === '') {
                continue;
            }
            try {
                $compiled = $template->getFetchFile($ref);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_string($compiled) || $compiled === '' || !is_file($compiled)) {
                continue;
            }
            $fp = $compiled . '|' . (string)@filesize($compiled) . '|' . (string)@filemtime($compiled);
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $php = @file_get_contents($compiled);
            if (!\is_string($php) || $php === '') {
                continue;
            }
            foreach (self::collectInlineSpecsFromPhp($php) as $spec) {
                $specs[] = $spec;
            }
        }
        RequestContext::set(self::SEEN_FILES_KEY, $seen);

        return $specs;
    }

    /**
     * Inline widget assets via {@see StorefrontPagePrefetchContributionInterface} provides.
     *
     * @param list<array{type:string,name:string,code:string,module:string}> $specs
     */
    private function delegateAssetPrefetch(array $specs): int
    {
        try {
            $registry = ObjectManager::getInstance(StorefrontPagePrefetchContributionRegistry::class);
            if (!$registry instanceof StorefrontPagePrefetchContributionRegistry) {
                return 0;
            }

            return $registry->prefetchInlineWidgetAssets($specs);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Framework page-level Phrase module union (RequestContext latch + one MGET).
     */
    private function delegatePageDictionaryPrefetch(Template $template, string ...$templateRefs): int
    {
        try {
            $coordinator = ObjectManager::getInstance(\Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator::class);
            if (!$coordinator instanceof \Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator) {
                return 0;
            }

            return $coordinator->primeBeforeLayoutFetch($template, ...$templateRefs);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Residual HotCache shared_read → prefetchPolicy batch (channel / meta / path.resolve).
     */
    private function delegateHotCachePagePrefetch(Template $template, string ...$templateRefs): int
    {
        try {
            $prefetch = ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontHotCachePagePrefetch::class);
            if (!$prefetch instanceof \Weline\Framework\Cache\Service\StorefrontHotCachePagePrefetch) {
                return 0;
            }

            return $prefetch->primeBeforeLayoutFetch($template, ...$templateRefs);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function matchingParenEnd(string $php, int $openParen): ?int
    {
        $depth = 0;
        $len = strlen($php);
        for ($i = $openParen; $i < $len; ++$i) {
            $ch = $php[$i];
            if ($ch === '(') {
                ++$depth;
                continue;
            }
            if ($ch === ')') {
                --$depth;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function extractExportedString(string $arrayBody, string $key): string
    {
        $pattern = "/['\"]" . preg_quote($key, '/') . "['\"]\\s*=>\\s*['\"]([^'\"]*)['\"]/";
        if (preg_match($pattern, $arrayBody, $m) !== 1) {
            return '';
        }

        return trim((string)$m[1]);
    }
}
