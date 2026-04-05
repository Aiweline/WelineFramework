<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\App\State;
use Weline\Framework\View\Template;

/**
 * ai_html 轨：拼接区块 HTML + 统一注入统计脚本（不走主题组件管线）
 */
final class AiHtmlRenderService
{
    public function __construct(
        private readonly Template $template,
    ) {
    }

    /**
     * @param 'visual'|'preview'|'live' $visualMode 与 PageRenderService 模式语义一致
     */
    public function render(Page $page, string $visualMode, ?string $locale = null): string
    {
        $currentLocale = $locale ?: State::getLang();
        $useDraft = ($visualMode !== PageRenderService::MODE_LIVE)
            || ((int)$page->getData(Page::schema_fields_STATUS) !== Page::STATUS_PUBLISHED);

        $layout = $page->resolveAiLayoutForFrontend($useDraft);
        $blocks = $layout['blocks'] ?? [];
        if (!\is_array($blocks)) {
            $blocks = [];
        }

        $parts = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $parts[] = (string)($block['html'] ?? '');
        }
        $bodyHtml = \implode("\n", $parts);

        $title = \trim((string)($page->getData(Page::schema_fields_META_TITLE) ?: $page->getData(Page::schema_fields_TITLE) ?: $page->getData(Page::schema_fields_NAME)));

        $homeConfig = $page->getHomePageConfig();
        $styleSettings = \is_array($homeConfig['style_setting'] ?? null) ? $homeConfig['style_setting'] : [];

        $this->template->assign('page', $page);
        $this->template->assign('style', $styleSettings);
        $this->template->assign('style_settings', $styleSettings);
        $this->template->assign('is_preview', $visualMode !== PageRenderService::MODE_LIVE);
        $this->template->assign('current_locale', $currentLocale);

        $tracking = '';
        try {
            $tracking = $this->template->fetch('GuoLaiRen_PageBuilder::templates/base/tracking.phtml', []);
        } catch (\Throwable) {
            $tracking = '';
        }

        $previewComment = $visualMode !== PageRenderService::MODE_LIVE
            ? "\n<!-- ai_html preview draft -->\n"
            : '';

        return '<!DOCTYPE html><html lang="'
            . \htmlspecialchars(\str_replace('_', '-', $currentLocale), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</title></head><body>'
            . $previewComment
            . $bodyHtml
            . "\n" . $tracking
            . '</body></html>';
    }
}
