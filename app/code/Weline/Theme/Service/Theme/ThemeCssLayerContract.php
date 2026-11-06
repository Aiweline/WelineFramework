<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Theme;

/**
 * 级联层序契约（single source of truth）。
 *
 * 背景（2026-11-06 架构收口）：层序字符串此前散落在 3 处且**互不一致**——
 * `view/ui/css/foundation.css` 含 `weline-theme`，而 `frontend.css` / `backend.css`
 * 与 `AuditService::LAYER_ORDER` 不含。只要先被解析的那份声明缺 `weline-theme`，
 * 浏览器就会把该层追加到序列末尾（= 最高优先级），默认主题骨架反过来被主题皮肤压过，
 * 与 theme.css 契约相反。改这里一处，CSS 侧由 S11 门禁保证每个 layout-critical 都带同一行。
 *
 * 语义（优先级由低到高，索引越大越优先）：
 * - `weline-theme`：默认主题皮肤层（theme.css 整体收敛于此），最低优先级；
 * - `reset` / `tokens` / `base` / `layout` / `components` / `utilities`：框架骨架分层；
 * - `page`：页面/模块末段覆盖（后台模块 CSS 使用）。
 *
 * 未分层（无 @layer）规则优先级恒高于以上任何层——这正是 layout-critical 必须保留
 * 「未分层」的原因（首屏 pin 必须压过对象包），也因此 `!important` 会变成不可覆盖的
 * 地雷，故 layout-critical 的强制显隐必须带条件（S12 门禁）。
 */
final class ThemeCssLayerContract
{
    /** @var list<string> 由低到高的层序 */
    public const ORDER = [
        'weline-theme',
        'reset',
        'tokens',
        'base',
        'layout',
        'components',
        'utilities',
        'page',
    ];

    /** 每个 ui CSS 文件与 layout-critical 都必须携带的层序声明原文 */
    public const DECLARATION = '@layer weline-theme, reset, tokens, base, layout, components, utilities, page;';

    public static function declaration(): string
    {
        return self::DECLARATION;
    }

    /**
     * 文件是否携带规范的层序声明（忽略大小写与多余空白）。
     */
    public static function isDeclared(string $css): bool
    {
        return str_contains(
            self::normalize($css),
            self::normalize(self::DECLARATION)
        );
    }

    /**
     * 是否含「层序声明」语句（逗号列表 + 分号），如 `@layer a, b;`。
     *
     * 与块语句 `@layer components { ... }` 区分：块语句不参与层序钉死，无需带规范层序。
     * 用于把「第一处序声明决定全部层序」这条 CSS 语义变成可审计的门禁。
     */
    public static function hasOrderStatement(string $css): bool
    {
        return self::firstOrderStatement($css) !== null;
    }

    /**
     * 返回原文中第一处层序声明（用于审计报文定位）；无则 null。
     */
    public static function firstOrderStatement(string $css): ?string
    {
        $offset = self::firstOrderStatementOffset($css);
        if ($offset === null) {
            return null;
        }

        $masked = self::maskComments($css);
        $pattern = '/@layer\s+[A-Za-z-]+(?:\s*,\s*[A-Za-z-]+)+\s*;/';
        if (preg_match($pattern, $masked, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        return substr($css, (int)$match[0][1], strlen((string)$match[0][0]));
    }

    /**
     * 第一处层序声明在**原文**中的字节偏移；无则 null。
     *
     * 注释内出现的示例声明不算（注释内容按等长空格抹除，保留换行与字节偏移，
     * 因此审计报文行号不会错位）。
     */
    public static function firstOrderStatementOffset(string $css): ?int
    {
        $masked = self::maskComments($css);
        $pattern = '/@layer\s+[A-Za-z-]+(?:\s*,\s*[A-Za-z-]+)+\s*;/';
        if (preg_match($pattern, $masked, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return (int)$match[0][1];
    }

    /**
     * 把块注释内容换成等长空格（保留换行），使偏移/行号与原文一致。
     */
    private static function maskComments(string $css): string
    {
        return (string)preg_replace_callback(
            '!/\*.*?\*/!s',
            static fn(array $m): string => (string)preg_replace('/[^\n]/', ' ', $m[0]),
            $css
        );
    }

    /**
     * 是否构成「层序被钉错」：写了序声明，但不是规范层序。
     */
    public static function violatesOrder(string $css): bool
    {
        return self::hasOrderStatement($css) && !self::isDeclared($css);
    }

    private static function normalize(string $css): string
    {
        $css = (string)preg_replace('!/\*.*?\*/!s', '', $css);
        $css = (string)preg_replace('/\s+/', ' ', $css);
        $css = (string)preg_replace('/\s*,\s*/', ',', $css);
        $css = (string)preg_replace('/\s*;\s*/', ';', $css);

        return $css;
    }
}
