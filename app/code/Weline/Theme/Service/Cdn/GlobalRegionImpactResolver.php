<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 把「哪些文件变了」翻译成「哪些页面/缓存前缀要失效」。
 *
 * 这是 CDN 渐进失效（P1–P7）的判定核心，不需要任何凭据即可测试：给它一组变更路径，
 * 它只回答「共享失效 / 精确页面 / 前缀」三类结果，真正去 purge 的适配器在外面。
 *
 * 规则（与全局区契约定下的影响面对齐）：
 *
 * - 全局区片段（`view/theme/base/**`、`frontend/widget/base/**`）→ **shared**：全站每页都有它。
 * - chrome / partials（`frontend/partials/**`、`view/theme/frontend/partials/**`）→ **shared**：外壳级。
 * - 布局（`frontend/layouts/{type}/**`）→ **exact**：只影响该布局对应的页面类型。
 * - 静态资源（`view/statics/**`、`/assets/**`）→ **static**：走 `?v=` 内容哈希，不需要 HTML 失效。
 * - 其他路径 → 保守按 **shared** 处理（宁可多失效，不可漏失效）。
 */
final class GlobalRegionImpactResolver
{
    /** @return array{shared:bool,exact:list<string>,static:bool,prefixes:list<string>,reasons:array<string,string>} */
    public function resolve(array $changedPaths): array
    {
        $result = [
            'shared' => false,
            'exact' => [],
            'static' => false,
            'prefixes' => [],
            'reasons' => [],
        ];

        foreach ($changedPaths as $path) {
            $normalized = \str_replace('\\', '/', \trim((string)$path));
            if ($normalized === '') {
                continue;
            }
            $this->classify($normalized, $result);
        }

        $result['exact'] = \array_values(\array_unique($result['exact']));
        $result['prefixes'] = \array_values(\array_unique($result['prefixes']));
        if ($result['shared']) {
            // 共享失效优先：全局内容变了，精确列表没有意义，避免上层再按 exact 小范围清理。
            $result['exact'] = [];
        }

        return $result;
    }

    /** @param array{shared:bool,exact:list<string>,static:bool,prefixes:list<string>,reasons:array<string,string>} $result */
    private function classify(string $path, array &$result): void
    {
        if ($this->isStatic($path)) {
            $result['static'] = true;
            $result['reasons'][$path] = 'static:asset_hash';

            return;
        }

        if ($this->isGlobalRegionFragment($path)) {
            $result['shared'] = true;
            $result['reasons'][$path] = 'shared:global_region_fragment';

            return;
        }

        if ($this->isChromePartial($path)) {
            $result['shared'] = true;
            $result['reasons'][$path] = 'shared:chrome_partial';

            return;
        }

        $layoutType = $this->layoutType($path);
        if ($layoutType !== null) {
            $result['exact'][] = $layoutType;
            $result['reasons'][$path] = 'exact:layout:' . $layoutType;

            return;
        }

        $result['shared'] = true;
        $result['reasons'][$path] = 'shared:unknown_conservative';
    }

    private function isStatic(string $path): bool
    {
        return \str_contains($path, '/view/statics/')
            || \str_contains($path, '/frontend/assets/')
            || \str_contains($path, '/view/theme/frontend/assets/');
    }

    private function isGlobalRegionFragment(string $path): bool
    {
        return \str_contains($path, '/view/theme/base/')
            || \str_contains($path, '/frontend/widget/base/');
    }

    private function isChromePartial(string $path): bool
    {
        return \str_contains($path, '/frontend/partials/')
            || \str_contains($path, '/view/theme/frontend/partials/')
            || \str_contains($path, '/view/hooks/');
    }

    /** `…/frontend/layouts/{type}/{file}.phtml` → `{type}` */
    private function layoutType(string $path): ?string
    {
        if (\preg_match('#/frontend/layouts/([a-z0-9_-]+)/#i', $path, $m) === 1) {
            return \strtolower($m[1]);
        }

        return null;
    }
}
