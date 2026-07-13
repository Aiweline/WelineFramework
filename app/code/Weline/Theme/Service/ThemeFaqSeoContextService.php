<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;

/**
 * 从主题布局 FAQ 部件收集 SEO FAQ 事实。
 */
class ThemeFaqSeoContextService
{
    /** @var string[] */
    private const FAQ_PAGE_TYPES = [
        'cms_page',
        'faq',
        'customer_service',
        'contact',
        'contact_page',
    ];

    public function __construct(
        private readonly ThemeLayoutService $layoutService
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolve(array $context): array
    {
        $pageType = $this->normalizePageType((string) ($context['page_type'] ?? ''));
        if (!$this->shouldCollectForPageType($pageType)) {
            return [];
        }

        $theme = ThemeData::getCurrentTheme();
        if ($theme === null || (int) $theme->getId() <= 0) {
            return [];
        }

        $layoutPageType = $pageType !== '' ? $pageType : ThemeLayout::PAGE_TYPE_DEFAULT;
        $layout = $this->layoutService->getPublishedLayout((int) $theme->getId(), $layoutPageType);
        $widgetFaqs = $this->extractWidgetFaqs($layout);
        if ($widgetFaqs === []) {
            return [];
        }

        // Theme 只提交事实；归一化与去重由 Seo 的唯一入口负责。
        $headContext = ['faqs' => $widgetFaqs];
        if ($pageType === '' || $pageType === 'cms_page') {
            $headContext['page_type'] = 'faq';
        }

        return $headContext;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<int, array<string, mixed>>
     */
    private function extractWidgetFaqs(array $layout): array
    {
        $faqs = [];
        foreach ($layout as $areaData) {
            if (!is_array($areaData)) {
                continue;
            }
            foreach ($areaData['widgets'] ?? [] as $widget) {
                $this->appendWidgetFaqs($faqs, $widget);
            }
            foreach ($areaData['slots'] ?? [] as $slotWidgets) {
                if (!is_array($slotWidgets)) {
                    continue;
                }
                foreach ($slotWidgets as $widget) {
                    $this->appendWidgetFaqs($faqs, $widget);
                }
            }
        }

        return $faqs;
    }

    /**
     * @param array<int, array<string, mixed>> $faqs
     * @param mixed $widget
     */
    private function appendWidgetFaqs(array &$faqs, mixed $widget): void
    {
        if (!is_array($widget)) {
            return;
        }
        if (($widget['widget_type'] ?? '') !== 'faq') {
            return;
        }

        $config = $widget['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($config) || !isset($config['faqs']) || !is_array($config['faqs'])) {
            return;
        }

        foreach ($config['faqs'] as $faq) {
            if (is_array($faq)) {
                $faqs[] = $faq;
            }
        }
    }

    private function shouldCollectForPageType(string $pageType): bool
    {
        if ($pageType === '') {
            return true;
        }

        return in_array($pageType, self::FAQ_PAGE_TYPES, true);
    }

    private function normalizePageType(string $pageType): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($pageType)));
    }
}
