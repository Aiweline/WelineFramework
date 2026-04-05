<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

/**
 * HTML 区块轨：占位生成与 scope 内 virtual_pages_by_type.blocks 填充
 */
final class AiSiteHtmlBlocksBuildService
{
    /**
     * @param array<string, mixed> $websiteProfile
     * @return list<array{block_id:string,type:string,html:string}>
     */
    public function buildPlaceholderBlocksForPageType(string $pageType, array $websiteProfile): array
    {
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? __('我的网站')));
        $brief = \trim((string)($websiteProfile['brief_description'] ?? ''));
        $label = (string)(Page::getPageTypes()[$pageType] ?? $pageType);

        $hero = \htmlspecialchars($siteTitle, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $sub = $brief !== '' ? \htmlspecialchars($brief, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') : '';

        $intro = $pageType === Page::TYPE_HOME
            ? '<p class="weline-pixel::section_view" data-section="hero">' . $sub . '</p>'
            : '<p class="weline-pixel::section_view">' . \htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . ' — ' . $sub . '</p>';

        return [
            [
                'block_id' => 'hero_' . $pageType,
                'type' => 'hero',
                'html' => '<section class="ai-block ai-block-hero"><h1>' . $hero . '</h1>' . $intro . '</section>',
            ],
            [
                'block_id' => 'main_' . $pageType,
                'type' => 'content',
                'html' => '<section class="ai-block ai-block-main"><p>'
                    . \htmlspecialchars(
                        (string)__('此区域为 AI 生成的 HTML 区块占位，可在工作区切换「高级虚拟主题」或发布前在可视化 API 中更新 blocks。'),
                        \ENT_QUOTES | \ENT_SUBSTITUTE,
                        'UTF-8'
                    )
                    . '</p></section>',
            ],
        ];
    }
}
