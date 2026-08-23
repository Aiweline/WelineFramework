<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

/**
 * Renders the data-driven fragments used by the full Theme Editor shell.
 *
 * The editor's business DOM and JavaScript contracts stay in the route template.
 * Only repeated option/list markup is centralized here so compiled templates do
 * not mix control flow with HTML.
 */
final class ThemeEditorMarkupRenderer
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly IconRegistry $icons)
    {
    }

    /**
     * @param array<int, array<string, mixed>|object> $themes
     */
    public function renderThemeOptions(array $themes, int $selectedThemeId): string
    {
        $html = '<option value="">' . $this->escape(__('-- 选择主题 --')) . '</option>';
        foreach ($themes as $theme) {
            $themeId = 0;
            $themeName = '';
            $isActive = false;
            if (is_array($theme)) {
                $themeId = (int)($theme['id'] ?? 0);
                $themeName = (string)($theme['name'] ?? '');
                $isActive = (bool)($theme['is_active'] ?? false);
            } elseif (is_object($theme)) {
                $themeId = method_exists($theme, 'getId') ? (int)$theme->getId() : 0;
                $themeName = method_exists($theme, 'getName') ? (string)($theme->getName() ?? '') : '';
                $isActive = method_exists($theme, 'getIsActive') && (bool)$theme->getIsActive();
            }
            $label = $themeName . ($isActive ? (string)__('（已启用）') : '');
            $html .= '<option value="' . $themeId . '"'
                . ($themeId === $selectedThemeId ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param list<array{value:string,label:string,kind:string}> $scopeOptions
     */
    public function renderScopeOptions(array $scopeOptions, string $selectedScope): string
    {
        $html = '';
        $allowedKinds = ['global', 'website', 'store', 'channel', 'custom'];
        foreach ($scopeOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = (string)($option['label'] ?? $value);
            $kind = strtolower(trim((string)($option['kind'] ?? 'custom')));
            if (!in_array($kind, $allowedKinds, true)) {
                $kind = 'custom';
            }
            $html .= '<option value="' . $this->escape($value) . '"'
                . ' data-scope-kind="' . $this->escape($kind) . '"'
                . ($value === $selectedScope ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $pageTypes
     */
    public function renderPageTypeOptions(array $pageTypes, string $selectedPageType): string
    {
        $html = '';
        foreach ($pageTypes as $code => $label) {
            $code = (string)$code;
            $html .= '<option value="' . $this->escape($code) . '"'
                . ($code === $selectedPageType ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $layoutOptions
     */
    public function renderLayoutOptions(array $layoutOptions, string $selectedLayout): string
    {
        $html = '';
        foreach ($layoutOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = (string)($option['value'] ?? '');
            $label = (string)($option['label'] ?? $value);
            $html .= '<option value="' . $this->escape($value) . '"'
                . ($value === $selectedLayout ? ' selected' : '')
                . '>' . $this->escape($label) . '</option>';
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>|object> $locales
     */
    public function renderLocaleOptions(array $locales): string
    {
        $html = '<option value="">' . $this->escape(__('默认（全语言）')) . '</option>';
        foreach ($locales as $locale) {
            $data = is_object($locale)
                ? (method_exists($locale, 'getData') ? (array)$locale->getData() : get_object_vars($locale))
                : (is_array($locale) ? $locale : []);
            $code = trim((string)($data['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string)($data['name'] ?? $code));
            $label = $name !== '' && $name !== $code ? $code . ' · ' . $name : $code;
            $html .= '<option value="' . $this->escape($code) . '" title="' . $this->escape($name) . '">'
                . $this->escape($label) . '</option>';
        }

        return $html;
    }

    public function renderEditorAreaOptions(bool $hasBackend, string $selectedArea): string
    {
        $html = '<option value="frontend"'
            . ($selectedArea === 'frontend' ? ' selected' : '')
            . '>' . $this->escape(__('前端')) . '</option>';
        if ($hasBackend) {
            $html .= '<option value="backend"'
                . ($selectedArea === 'backend' ? ' selected' : '')
                . '>' . $this->escape(__('后端')) . '</option>';
        }

        return $html;
    }

    public function renderStructurePlaceholder(string $area): string
    {
        $definitions = [
            'header' => [
                'icon' => 'monitor',
                'title' => '头部区域',
                'description' => '拖入头部部件',
                'tips' => [
                    ['image', 'Logo'],
                    ['menu', '导航'],
                    ['search', '搜索'],
                    ['user', '账户'],
                    ['cart', '购物车'],
                ],
            ],
            'content' => [
                'icon' => 'grid',
                'title' => '内容区域',
                'description' => '拖拽部件或容器到此处',
                'tips' => [
                    ['layout-row', '行布局'],
                    ['layout-column', '列布局'],
                    ['image', 'Banner'],
                    ['slideshow', '轮播'],
                    ['box', '产品'],
                ],
            ],
            'footer' => [
                'icon' => 'layout-footer',
                'title' => '底部区域',
                'description' => '拖入底部部件',
                'tips' => [
                    ['link', '链接'],
                    ['mail', '订阅'],
                    ['share', '社交'],
                    ['copyright', '版权'],
                ],
            ],
        ];
        $definition = $definitions[$area] ?? $definitions['content'];
        $html = '<div class="slot-placeholder-large"><div class="placeholder-icon">'
            . $this->icons->render((string)$definition['icon'], 'sm')
            . '</div><div class="placeholder-title">' . $this->escape(__((string)$definition['title'])) . '</div>'
            . '<div class="placeholder-text">' . $this->escape(__((string)$definition['description'])) . '</div>'
            . '<div class="placeholder-tips">';
        foreach ($definition['tips'] as $tip) {
            $html .= '<span class="tip-item">' . $this->icons->render((string)$tip[0], 'sm')
                . ' ' . $this->escape(__((string)$tip[1])) . '</span>';
        }

        return $html . '</div></div>';
    }

    /**
     * @param array<string, mixed> $groups
     */
    public function renderWidgetLibrary(array $groups, string $editorArea): string
    {
        if ($groups === []) {
            return '<div class="widget-list-loading" id="widgetListLoading">'
                . '<span class="w-spinner" role="status"><span class="w-visually-hidden">'
                . $this->escape(__('加载中...')) . '</span></span>'
                . '<span class="widget-list-loading-text">' . $this->escape(__('部件库加载中...')) . '</span></div>';
        }

        $html = '';
        foreach ($groups as $type => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupLabel = (string)($group['label'] ?? $type);
            $widgets = $group['widgets'] ?? [];
            $widgets = is_array($widgets) ? $widgets : [];
            $html .= '<div class="widget-group" data-type="' . $this->escape($type) . '" data-state="open">'
                . '<button type="button" class="widget-group-header" aria-expanded="true">'
                . $this->icons->render('chevron-down', 'sm', '', 'w-theme-editor-toggle-icon')
                . '<span>' . $this->escape($groupLabel) . '</span>'
                . '<span class="widget-count">' . count($widgets) . '</span></button>'
                . '<div class="widget-group-content">';
            foreach ($widgets as $widget) {
                if (is_array($widget)) {
                    $html .= $this->renderWidgetLibraryItem($widget, $editorArea);
                }
            }
            $html .= '</div></div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function renderWidgetLibraryItem(array $widget, string $editorArea): string
    {
        $code = (string)($widget['code'] ?? '');
        $module = (string)($widget['module'] ?? '');
        $type = (string)($widget['type'] ?? '');
        $name = trim((string)($widget['name'] ?? ''));
        if ($name === '') {
            $namePart = basename(str_replace('\\', '/', $code));
            $name = $namePart !== ''
                ? ucwords(str_replace(['-', '_'], ' ', $namePart))
                : (string)__('未命名部件');
        }
        $description = trim((string)($widget['description'] ?? ''));
        if ($description === '' && $code !== '') {
            $description = (string)__('可拖拽到页面插槽的基础组件');
        }
        $position = $widget['position'] ?? [];
        $compatible = (bool)($widget['compatible'] ?? false);
        $slot = (string)($widget['slot'] ?? '');
        $supports = $widget['supports'] ?? [];
        $supports = is_array($supports) ? array_map('strval', $supports) : [(string)$supports];
        $slots = is_array($widget['slots'] ?? null) ? $widget['slots'] : [];
        $slotCodes = array_values(array_filter(array_unique(array_merge(
            $supports,
            array_map('strval', array_keys($slots))
        )), static fn (string $item): bool => $item !== ''));
        $pageLayouts = $widget['page_layouts'] ?? ['*'];
        $isContainer = (bool)($widget['is_container'] ?? false);
        $exclusiveSlots = ['logo', 'search', 'main-nav', 'header-container', 'footer-container', 'content-container'];
        $exclusive = (bool)($widget['exclusive'] ?? false)
            || ($slot !== '' && in_array($slot, $exclusiveSlots, true));
        $classes = 'widget-item draggable'
            . ($isContainer ? ' widget-container' : '')
            . ($exclusive ? ' widget-exclusive' : '');
        $containerBadge = $isContainer
            ? '<span class="w-badge" data-tone="primary" title="' . $this->escape(__('容器部件')) . '">'
                . $this->icons->render('grid', 'sm') . '</span>'
            : '';
        $exclusiveBadge = $exclusive
            ? '<span class="w-badge" data-tone="warning" title="' . $this->escape(__('独占部件')) . '">'
                . $this->icons->render('eye', 'sm') . '</span>'
            : '';
        $previewHtml = is_string($widget['preview_html'] ?? null) ? $widget['preview_html'] : '';
        $addLabel = $this->escape(__('添加到当前插槽'));
        $previewLabel = $this->escape(__('预览'));

        return '<div class="' . $classes . '" draggable="true"'
            . ' data-widget-code="' . $this->escape($code) . '"'
            . ' data-widget-module="' . $this->escape($module) . '"'
            . ' data-widget-type="' . $this->escape($type) . '"'
            . ' data-widget-name="' . $this->escape($name) . '"'
            . ' data-widget-position="' . $this->jsonAttribute($position) . '"'
            . ' data-widget-compatible="' . ($compatible ? '1' : '0') . '"'
            . ' data-widget-slot="' . $this->escape($slot) . '"'
            . ' data-widget-supports="' . $this->escape(implode(',', $slotCodes)) . '"'
            . ' data-widget-slots="' . $this->escape(implode(',', array_map('strval', array_keys($slots)))) . '"'
            . ' data-widget-exclusive="' . ($exclusive ? '1' : '0') . '"'
            . ' data-widget-page-layouts="' . $this->jsonAttribute($pageLayouts) . '"'
            . ' data-widget-is-container="' . ($isContainer ? '1' : '0') . '">'
            . '<div class="widget-preview"><div class="widget-preview-overlay">'
            . '<div class="widget-preview-title-row"><div class="widget-preview-title" title="' . $this->escape($name) . '">'
            . $this->escape($name) . $containerBadge . $exclusiveBadge . '</div>'
            . '<div class="w-theme-editor-widget-actions">'
            . '<button type="button" class="w-button w-theme-editor-add-component" data-tone="primary" data-size="sm" data-icon-only="true" title="'
            . $addLabel . '" aria-label="' . $addLabel . '">' . $this->icons->render('plus', 'sm') . '</button>'
            . '<button type="button" class="w-button w-theme-editor-preview-component" data-tone="neutral" data-variant="outline" data-size="sm" data-icon-only="true" title="'
            . $previewLabel . '" data-widget-module="' . $this->escape($module) . '" data-widget-code="' . $this->escape($code)
            . '" data-widget-name="' . $this->escape($name) . '">' . $this->icons->render('eye', 'sm') . '</button>'
            . '</div></div><div class="widget-preview-desc" title="' . $this->escape($description) . '">'
            . $this->escape($description) . '</div></div>'
            . '<div class="widget-preview-canvas" data-widget-module="' . $this->escape($module)
            . '" data-widget-code="' . $this->escape($code) . '" data-widget-area="' . $this->escape($editorArea) . '">'
            . $previewHtml . '</div></div></div>';
    }

    /**
     * @param mixed $value
     */
    private function jsonAttribute(mixed $value): string
    {
        return $this->escape(json_encode($value, self::JSON_FLAGS) ?: '[]');
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
