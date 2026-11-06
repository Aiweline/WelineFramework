<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * 页面与接口共用的**唯一**共享性决策点。
 *
 * 只做判定：不读请求态、不计数、不落盘、不带任何实例状态。
 * 请求态由调用方（{@see \Weline\Framework\Router\FullPageCacheCoordinator::decideShareability()}
 * 负责采集后传进来；因此本类可被单测直接驱动，且每请求多次求值都是幂等的。
 *
 * 决策顺序（fail-closed，先否决后放行）：
 *   1. method 非 GET/HEAD                        → none / method
 *   2. SSE 请求                                   → none / sse
 *   3. 编辑器 / 预览旁路                          → none / editor
 *   4. 硬门（isExcludedFrontendPath）             → none / hard_excluded
 *   5. policy.enabled !== true                    → none / policy_disabled
 *   6. 叶类型（见下）                              → none / content_type | unacceptable_accept
 *   7. bq 叶 + 已登录会话                          → none / logged_in
 *   8. 其余                                       → shareable
 *
 * 叶类型的两条口径（v8 决定于风险审查，与计划原文有意偏离）：
 *   - `content_type` 非空（发布期）= 按**响应** Content-Type 定叶。理由：
 *     Accept 通配（fetch / curl / 爬虫默认的「全类型接受」）在
 *     {@see \Weline\Framework\Router\FullPageCacheCoordinator::acceptHeaderAllowsHtml()}
 *     下会被判成「接受 HTML」，只看 Accept 会让 JSON 接口永远落 index.html.br。
 *   - `content_type` 为空（服务期 / 读侧）= 按 Accept 定叶。
 *
 * 登录门只作用于 bq 叶。理由：页面侧有既有约定
 * 「Storefront SSR is the anonymous shell. Login session does not decide serve or build.」
 * （FullPageCacheCoordinator 的 canServeCachedResponse 注释），页面叶吃登录门会让
 * 已登录顾客全部回源，是线上性能回退；接口叶吃登录门才是「个人化 JSON 不共享」的安全边界。
 *
 * 出站缓存头**不在**这里写：源站短 TTL 与边缘封顶唯一由
 * `applyFpcHitEdgeCacheHeaders()` 负责。
 */
final class ResponseShareabilityDecider
{
    /** 整页 / HTML 文档叶（html-disk `index.html.br`）。 */
    public const LEAF_HTML = 'html';

    /** 接口 / BinQuery wire 叶（html-disk `index.bq.br`）。 */
    public const LEAF_BQ = 'bq';

    /** 不共享，不落任何叶。 */
    public const LEAF_NONE = 'none';

    public const REASON_METHOD = 'method';
    public const REASON_SSE = 'sse';
    public const REASON_EDITOR = 'editor';
    public const REASON_HARD_EXCLUDED = 'hard_excluded';
    public const REASON_POLICY_DISABLED = 'policy_disabled';
    public const REASON_LOGGED_IN = 'logged_in';
    public const REASON_CONTENT_TYPE = 'content_type';
    public const REASON_UNACCEPTABLE_ACCEPT = 'unacceptable_accept';

    /**
     * @param array<string, mixed> $input
     *        method(string) path(string) policy(array{enabled:bool,ttl:int})
     *        accepts_html(bool) accepts_json(bool) has_logged_in_session(bool)
     *        is_sse(bool) is_editor_or_preview(bool) is_excluded_path(bool)
     *        content_type(?string，仅发布期给)
     *
     * @return array{shareable: bool, leaf: string, reason: string, ttl: int}
     */
    public function decide(array $input): array
    {
        $method = \strtoupper(\trim((string)($input['method'] ?? 'GET')));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::none(self::REASON_METHOD);
        }

        if ((bool)($input['is_sse'] ?? false)) {
            return self::none(self::REASON_SSE);
        }
        if ((bool)($input['is_editor_or_preview'] ?? false)) {
            return self::none(self::REASON_EDITOR);
        }
        if ((bool)($input['is_excluded_path'] ?? false)) {
            return self::none(self::REASON_HARD_EXCLUDED);
        }

        $policy = $input['policy'] ?? null;
        if (!\is_array($policy) || ($policy['enabled'] ?? null) !== true) {
            return self::none(self::REASON_POLICY_DISABLED);
        }
        $ttl = (int)($policy['ttl'] ?? 0);

        $contentType = \strtolower(\trim((string)($input['content_type'] ?? '')));
        if ($contentType !== '') {
            $leaf = self::leafFromContentType($contentType);
            if ($leaf === self::LEAF_NONE) {
                return self::none(self::REASON_CONTENT_TYPE);
            }
        } else {
            $leaf = self::leafFromAccept(
                (bool)($input['accepts_html'] ?? false),
                (bool)($input['accepts_json'] ?? false),
            );
            if ($leaf === self::LEAF_NONE) {
                return self::none(self::REASON_UNACCEPTABLE_ACCEPT);
            }
        }

        if ($leaf === self::LEAF_BQ && (bool)($input['has_logged_in_session'] ?? false)) {
            return self::none(self::REASON_LOGGED_IN);
        }

        return [
            'shareable' => true,
            'leaf' => $leaf,
            'reason' => $leaf,
            'ttl' => $ttl,
        ];
    }

    private static function leafFromContentType(string $contentType): string
    {
        if (\str_contains($contentType, 'text/html') || \str_contains($contentType, 'application/xhtml')) {
            return self::LEAF_HTML;
        }
        if (\str_contains($contentType, 'application/json') || \str_contains($contentType, '+json')) {
            return self::LEAF_BQ;
        }

        return self::LEAF_NONE;
    }

    private static function leafFromAccept(bool $acceptsHtml, bool $acceptsJson): string
    {
        if ($acceptsHtml) {
            return self::LEAF_HTML;
        }
        if ($acceptsJson) {
            return self::LEAF_BQ;
        }

        return self::LEAF_NONE;
    }

    /** @return array{shareable: bool, leaf: string, reason: string, ttl: int} */
    private static function none(string $reason): array
    {
        return [
            'shareable' => false,
            'leaf' => self::LEAF_NONE,
            'reason' => $reason,
            'ttl' => 0,
        ];
    }
}
