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

        $bodyHtml = $visualMode === PageRenderService::MODE_VISUAL
            ? $this->renderVisualBlocks($blocks, $page)
            : $this->renderPlainBlocks($blocks);

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

        $visualAssets = $visualMode === PageRenderService::MODE_VISUAL
            ? $this->renderVisualAssets($page)
            : '';

        return '<!DOCTYPE html><html lang="'
            . \htmlspecialchars(\str_replace('_', '-', $currentLocale), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . $visualAssets
            . '</head><body data-page-type="' . \htmlspecialchars((string)($page->getData(Page::schema_fields_TYPE) ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">'
            . $previewComment
            . $bodyHtml
            . "\n" . $tracking
            . '</body></html>';
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function renderPlainBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $parts[] = (string)($block['html'] ?? '');
        }

        return \implode("\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function renderVisualBlocks(array $blocks, Page $page): string
    {
        $parts = [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }

            $blockId = \trim((string)($block['block_id'] ?? ''));
            if ($blockId === '') {
                $blockId = 'block-' . \bin2hex(\random_bytes(4));
            }
            $blockType = \trim((string)($block['type'] ?? 'section'));
            $region = $this->inferBlockRegion($blockType, $index);
            $label = $this->buildBlockLabel($blockId, $blockType, $region);
            $actions = $this->buildVisualBlockActionsHtml($blockId, $region, (int)$index, (string)($page->getData(Page::schema_fields_TYPE) ?? ''));
            $parts[] = '<div class="pb-ai-block-wrapper tpmst-component-wrapper pb-component-wrapper"'
                . ' data-component="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-region="' . \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-index="' . (int)$index . '"'
                . ' data-page-type="' . \htmlspecialchars((string)($page->getData(Page::schema_fields_TYPE) ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ' data-block-type="' . \htmlspecialchars($blockType, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">'
                . '<div class="pb-ai-block-label">' . \htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</div>'
                . $actions
                . (string)($block['html'] ?? '')
                . '</div>';
        }

        return \implode("\n", $parts);
    }

    private function renderVisualAssets(Page $page): string
    {
        $pageType = \htmlspecialchars((string)($page->getData(Page::schema_fields_TYPE) ?? ''), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<style>
            .pb-ai-block-wrapper {
                position: relative;
                overflow: visible;
                isolation: isolate;
                transition: box-shadow 0.2s ease;
            }
            .pb-ai-block-wrapper:hover {
                box-shadow: inset 0 0 0 2px rgba(37, 99, 235, 0.45);
                z-index: 10001;
            }
            .pb-ai-block-label {
                position: absolute;
                top: 8px;
                left: 8px;
                z-index: 99998;
                display: inline-flex;
                align-items: center;
                padding: 6px 10px;
                border-radius: 999px;
                background: rgba(15, 23, 42, 0.86);
                color: #fff;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                pointer-events: none;
            }
            .pb-ai-block-wrapper .component-actions {
                position: absolute !important;
                top: 8px !important;
                right: 8px !important;
                display: none !important;
                gap: 6px;
                z-index: 99999 !important;
                background: rgba(255,255,255,0.96) !important;
                padding: 6px 8px !important;
                border-radius: 8px !important;
                box-shadow: 0 2px 12px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05) !important;
            }
            .pb-ai-block-wrapper:hover .component-actions,
            .pb-ai-block-wrapper .component-actions:hover,
            .pb-ai-block-wrapper .component-actions.pb-actions-visible,
            .pb-ai-block-wrapper.selected .component-actions {
                display: flex !important;
            }
            .pb-ai-block-wrapper .component-action-btn {
                border: 0;
                border-radius: 999px;
                padding: 8px 12px;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
            }
            .pb-ai-block-wrapper .component-action-refine {
                background: #2563eb;
                color: #fff;
            }
            .pb-ai-block-wrapper .component-action-editor {
                background: #e2e8f0;
                color: #0f172a;
            }
        </style>
        <script>
            (function() {
                var hasParentWindow = window.parent && window.parent !== window;
                if (!hasParentWindow) {
                    document.querySelectorAll(".component-actions").forEach(function(actions) {
                        actions.remove();
                    });
                    return;
                }
                document.querySelectorAll(".pb-ai-block-wrapper").forEach(function(wrapper) {
                    wrapper.addEventListener("click", function(e) {
                        if (e.target && e.target.closest && e.target.closest(".component-actions")) {
                            return;
                        }
                        e.stopPropagation();
                        document.querySelectorAll(".pb-ai-block-wrapper.selected").forEach(function(el) {
                            el.classList.remove("selected");
                        });
                        this.classList.add("selected");
                        window.parent.postMessage({
                            type: "pb-component-select",
                            component: this.dataset.component || "",
                            region: this.dataset.region || "",
                            index: this.dataset.index || "",
                            page_type: this.dataset.pageType || "' . $pageType . '"
                        }, "*");
                    });
                });
                function toggleWrapperActions(wrapper, visible) {
                    if (!wrapper) {
                        return;
                    }
                    var actions = wrapper.querySelector(".component-actions");
                    if (!actions) {
                        return;
                    }
                    if (visible) {
                        actions.classList.add("pb-actions-visible");
                    } else {
                        actions.classList.remove("pb-actions-visible");
                    }
                }
                document.addEventListener("mouseover", function(e) {
                    var wrapper = e.target && e.target.closest ? e.target.closest(".pb-ai-block-wrapper") : null;
                    if (!wrapper) {
                        return;
                    }
                    toggleWrapperActions(wrapper, true);
                }, true);
                document.addEventListener("mouseout", function(e) {
                    var wrapper = e.target && e.target.closest ? e.target.closest(".pb-ai-block-wrapper") : null;
                    if (!wrapper) {
                        return;
                    }
                    var related = e.relatedTarget;
                    if (related && wrapper.contains(related)) {
                        return;
                    }
                    toggleWrapperActions(wrapper, false);
                }, true);
                document.querySelectorAll(".component-actions [data-pb-action]").forEach(function(button) {
                    button.addEventListener("click", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        var wrapper = this.closest(".pb-ai-block-wrapper");
                        if (!wrapper) {
                            return;
                        }
                        window.parent.postMessage({
                            type: "pb-component-action",
                            action: this.getAttribute("data-pb-action") || "",
                            component: wrapper.dataset.component || "",
                            region: wrapper.dataset.region || "",
                            index: wrapper.dataset.index || "",
                            page_type: wrapper.dataset.pageType || "' . $pageType . '"
                        }, "*");
                    });
                });
            })();
        </script>';
    }

    private function inferBlockRegion(string $blockType, int $index): string
    {
        $type = \strtolower($blockType);
        if ($type === 'site_header' || \str_starts_with($type, 'ai_generated_shared_header')) {
            return 'header';
        }
        if ($type === 'site_footer' || \str_starts_with($type, 'ai_generated_shared_footer')) {
            return 'footer';
        }
        if (\str_contains($type, 'header') || \str_contains($type, 'hero')) {
            return $index === 0 ? 'header' : 'content';
        }
        if (\str_contains($type, 'footer') || \str_contains($type, 'cta')) {
            return 'footer';
        }

        return 'content';
    }

    private function buildBlockLabel(string $blockId, string $blockType, string $region): string
    {
        $cleanRegionLabel = match ($region) {
            'header' => '页头',
            'footer' => '页脚',
            default => '内容块',
        };
        $source = \trim($blockId !== '' ? $blockId : $blockType);
        if (\str_contains($source, '/')) {
            $source = (string)\substr($source, (int)\strrpos($source, '/') + 1);
        }
        $source = (string)\preg_replace('/^(?:header|footer)-|^home-page-|^about-page-|^ai-generated-/i', '', $source);
        $source = \trim((string)\preg_replace('/[-_]+/', ' ', $source));
        $source = $source !== '' ? $source : 'section';

        return $cleanRegionLabel . ' · ' . $source;
    }

    private function buildVisualBlockActionsHtml(string $blockId, string $region, int $index, string $pageType): string
    {
        return '<div class="component-actions"'
            . ' data-page-type="' . \htmlspecialchars($pageType, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' data-component="' . \htmlspecialchars($blockId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' data-region="' . \htmlspecialchars($region, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' data-index="' . (int)$index . '">'
            . '<button type="button" class="component-action-btn component-action-refine" data-pb-action="refine" title="AI 微调当前区块">AI 微调</button>'
            . '<button type="button" class="component-action-btn component-action-editor" data-pb-action="open-editor" title="定位到当前页编辑器">编辑器</button>'
            . '</div>';
    }
}
