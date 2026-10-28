<?php

declare(strict_types=1);

namespace Weline\Frontend\Observer;

use Weline\Framework\Deploy\StaticAssetHtmlRewriter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeHeadCssDuplicateInspector;

/**
 * 最终 HTML 统一加静态构建代次：/static/... → /static/{buildId}/...
 * 这样模板里历史硬编码的 CSS/JS/图片也和老 FPC 页面之外的资源同代。
 *
 * 另带 DEV 断言：最终 HTML 同时出现 theme-head overlay 包与包内已有的主题叶时，说明同一份
 * CSS 投递了两次（layout/page 重复挂叶）。head chrome 走缓存会跳过发包，只有这里才看得到
 * 真实装配结果。断言只允许 DEV 生效（用户 2026-10-28 指定），且永不抛异常。
 */
final class StaticAssetResponseRewriter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $response = $event->getData('response');
        if (!$response instanceof Response || $response->getStatusCode() !== 200) {
            return;
        }
        $contentType = $response->getHeader('Content-Type');
        $contentType = \strtolower(\is_array($contentType) ? (string)\reset($contentType) : (string)$contentType);
        if ($contentType !== '' && !\str_contains($contentType, 'text/html')) {
            return;
        }
        $body = $response->getBody();
        if ($body === '' || (!\str_contains($body, '/static/') && !\str_contains($body, StaticAssetHtmlRewriter::PAIR_PLACEHOLDER) && !\str_contains($body, StaticAssetHtmlRewriter::DEPLOY_PLACEHOLDER))) {
            return;
        }
        $rewritten = StaticAssetHtmlRewriter::rewrite($body);
        if ($rewritten !== $body) {
            $response->setBody($rewritten);
        }
        $this->assertHeadCssNoDuplicateLeaf($rewritten, $response);
    }

    /**
     * DEV 断言：同一份 theme-head CSS 被真加载两次 = 多一个请求。
     *
     * 判据交给 ThemeHeadCssDuplicateInspector::inspectHtml()（noscript 回退与
     * preload+onload 只算一次加载）。这里只负责分级落日志：
     * - hard（duplicate）→ error 日志 + HTML 注释标记，便于页面源码里直接看到；
     * - warning（preload_no_fallback 等）→ warning 日志，不污染 duplicate 标记。
     *
     * PROD 直接返回（不记日志、不打标记、不改字节）。内部异常一律吞掉：
     * 断言是开发期保障，绝不能把页面打挂。
     */
    private function assertHeadCssNoDuplicateLeaf(string $html, Response $response): void
    {
        if (\defined('PROD') && \PROD) {
            return;
        }
        // 任何 theme-head 包在场都可能与叶重复；不限于 overlay（同 URL 重复与包类型无关）。
        if (!\str_contains($html, 'data-weline-theme-head-pack="')) {
            return;
        }
        try {
            /** @var ThemeHeadChromeCssPack $headPack */
            $headPack = ObjectManager::getInstance(ThemeHeadChromeCssPack::class);
            // 优先用当前主题（页面刚渲染过，ThemeData 已就绪）；解析失败再退回请求上下文。
            $theme = null;
            try {
                $current = ThemeData::getCurrentTheme();
                $theme = $current instanceof WelineTheme && (int)$current->getId() > 0 ? $current : null;
            } catch (\Throwable) {
                $theme = null;
            }
            $leaves = $headPack->overlayManifestLeaves('frontend', $theme);
            if ($leaves === []) {
                $leaves = $headPack->overlayManifestLeaves('frontend');
            }
            if ($leaves === []) {
                return;
            }
            /** @var ThemeHeadCssDuplicateInspector $inspector */
            $inspector = ObjectManager::getInstance(ThemeHeadCssDuplicateInspector::class);
            $findings = $inspector->inspectHtml($html, $leaves);
            $hard = $inspector->hardFindings($findings);
        } catch (\Throwable) {
            return;
        }
        if ($findings === []) {
            return;
        }
        $warnings = \array_values(\array_filter(
            $findings,
            static fn(array $f): bool => ($f['severity'] ?? '') !== ThemeHeadCssDuplicateInspector::SEVERITY_DUPLICATE
        ));
        if ($warnings !== []) {
            try {
                if (\function_exists('w_log_warning')) {
                    w_log_warning('[theme-head-css] ' . \implode('; ', \array_column($warnings, 'reason'))
                        . ' leaves=' . \implode(',', \array_column($warnings, 'leaf'))
                        . ' uri=' . (string)($_SERVER['REQUEST_URI'] ?? ''));
                }
            } catch (\Throwable) {
            }
        }
        if ($hard === []) {
            return;
        }
        $duplicated = \implode(',', \array_column($hard, 'leaf'));
        try {
            w_log_error('[theme-head-css] duplicate leaf delivered: ' . $duplicated
                . ' uri=' . (string)($_SERVER['REQUEST_URI'] ?? ''));
        } catch (\Throwable) {
        }
        $marker = '<!-- weline-head-css-duplicate: '
            . \htmlspecialchars($duplicated, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . ' -->';
        $patched = \preg_replace('/<head\b[^>]*>/i', '$0' . $marker, $html, 1);
        if (\is_string($patched) && $patched !== $html) {
            $response->setBody($patched);
        }
    }
}
