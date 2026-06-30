<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * Widget 参数类型抽象基类
 *
 * 提供通用的 HTML 渲染辅助方法和默认实现。
 * 所有可翻译字段的多语言 UI 由 renderTranslatableWrap() 统一包装，
 * 各子类无需关心 i18n 实现。
 */
abstract class AbstractParamType implements WidgetParamTypeInterface
{
    protected const CSS_PREFIX = 'w-param-';

    /** 天然需要翻译的字段类型 */
    private const TRANSLATABLE_TYPES = ['string', 'textarea', 'html', 'text'];

    /**
     * 推断字段是否可翻译：显式 i18n 优先，否则文本类默认 true
     * 关闭多语言：'i18n' => false
     */
    public static function isTranslatable(array $param): bool
    {
        if (array_key_exists('i18n', $param)) {
            return (bool)$param['i18n'];
        }
        $type = $param['type'] ?? 'string';
        return in_array($type, self::TRANSLATABLE_TYPES, true);
    }

    protected function generateFieldId(string $key, int|string $layoutId): string
    {
        return 'config_' . $layoutId . '_' . str_replace('.', '_', $key);
    }

    protected function buildAttrString(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === true) {
                $parts[] = htmlspecialchars($name);
            } elseif ($value !== false && $value !== null) {
                $parts[] = htmlspecialchars($name) . '="' . htmlspecialchars((string)$value) . '"';
            }
        }
        return implode(' ', $parts);
    }

    protected function renderFieldStart(string $key, array $param, int|string $layoutId): string
    {
        $p = self::CSS_PREFIX;
        $translatable = self::isTranslatable($param);
        $fieldClass = $translatable ? $p . 'field ' . $p . 'translatable' : $p . 'field';
        return '<div class="' . $fieldClass . '" data-field-key="' . htmlspecialchars($key) . '" data-translatable="' . ($translatable ? 'true' : 'false') . '">';
    }

    /**
     * 统一多语言包装入口
     *
     * translatable=true  → 字段头（label + 多语言按钮）+ 输入区 + 空 i18n 面板容器
     * translatable=false → 字段头（仅 label）+ 输入区
     *
     * $context 可含 array_key / array_index，用于数组子字段的 data 属性。
     */
    public function renderTranslatableWrap(
        string $key,
        array $param,
        int|string $layoutId,
        string $inputHtml,
        array $context = []
    ): string {
        $p = self::CSS_PREFIX;
        $translatable = self::isTranslatable($param);
        $label = $param['label'] ?? $param['name'] ?? $key;
        $fieldId = $this->generateFieldId($key, $layoutId);
        $required = $param['required'] ?? false;

        $html = '<div class="' . $p . 'field-header">';
        $html .= '<label class="' . $p . 'label" for="' . htmlspecialchars($fieldId) . '">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="' . $p . 'required">*</span>';
        }
        $html .= '</label>';

        if ($translatable) {
            $dataAttrs = 'data-field="' . htmlspecialchars($key) . '" data-layout-id="' . htmlspecialchars((string)$layoutId) . '"';
            if (!empty($context['array_key'])) {
                $dataAttrs .= ' data-array-key="' . htmlspecialchars($context['array_key']) . '"';
            }
            if (isset($context['array_index'])) {
                $dataAttrs .= ' data-array-index="' . htmlspecialchars((string)$context['array_index']) . '"';
            }
            $html .= '<button type="button" class="' . $p . 'btn-i18n" ' . $dataAttrs . ' title="' . __('编辑多语言') . '">';
            $html .= '<span>' . __('多语言') . '</span>';
            $html .= '</button>';
        }

        $html .= '</div>';

        $html .= '<div class="' . $p . 'field-input">' . $inputHtml . '</div>';

        if ($translatable) {
            $html .= $this->renderI18nPanel($key, $layoutId, $context);
        }

        return $html;
    }

    /**
     * 统一 i18n 面板：空容器，由前端动态填充语言列表
     */
    protected function renderI18nPanel(string $key, int|string $layoutId, array $context = []): string
    {
        $p = self::CSS_PREFIX;
        $panelId = 'i18n_panel_' . $layoutId . '_' . str_replace('.', '_', $key);

        $dataAttrs = 'data-field="' . htmlspecialchars($key) . '" data-layout-id="' . htmlspecialchars((string)$layoutId) . '"';
        if (!empty($context['array_key'])) {
            $dataAttrs .= ' data-array-key="' . htmlspecialchars($context['array_key']) . '"';
        }
        if (isset($context['array_index'])) {
            $dataAttrs .= ' data-array-index="' . htmlspecialchars((string)$context['array_index']) . '"';
        }

        $html = '<div class="' . $p . 'i18n-panel" id="' . htmlspecialchars($panelId) . '" ' . $dataAttrs . ' style="display:none;">';
        $html .= '<div class="' . $p . 'i18n-header">';
        $html .= '<span>' . __('多语言配置') . '</span>';
        $html .= '<button type="button" class="' . $p . 'btn ' . $p . 'btn-outline-secondary ' . $p . 'btn-sm" data-close-i18n data-field="' . htmlspecialchars($key) . '">×</button>';
        $html .= '</div>';
        // 空 body，由前端 fetchInstalledLocales() 后动态填充
        $html .= '<div class="' . $p . 'i18n-body"></div>';
        $html .= '<div class="' . $p . 'i18n-footer">';
        $html .= '<button type="button" class="' . $p . 'btn ' . $p . 'btn-outline-secondary ' . $p . 'btn-sm btn-ai-i18n" data-ai-i18n ' . $dataAttrs . '>' . __('AI翻译') . '</button>';
        $html .= '<button type="button" class="' . $p . 'btn ' . $p . 'btn-primary ' . $p . 'btn-sm" data-save-i18n ' . $dataAttrs . '>' . __('保存多语言') . '</button>';
        $html .= '</div></div>';
        return $html;
    }

    protected function renderFieldDescription(array $param): string
    {
        $description = $param['description'] ?? '';
        if (empty($description)) {
            return '';
        }
        return '<div class="' . self::CSS_PREFIX . 'field-desc">' . htmlspecialchars($description) . '</div>';
    }

    protected function renderFieldEnd(): string
    {
        return '</div>';
    }

    /**
     * 完整字段包装：外层 div + renderTranslatableWrap + description + 关闭 div
     */
    protected function wrapField(string $key, array $param, string $inputHtml, int|string $layoutId): string
    {
        $html = $this->renderFieldStart($key, $param, $layoutId);
        $html .= $this->renderTranslatableWrap($key, $param, $layoutId, $inputHtml);
        $html .= $this->renderFieldDescription($param);
        $html .= $this->renderFieldEnd();
        return $html;
    }

    public function validate(mixed $value, array $param): bool
    {
        $required = $param['required'] ?? false;
        if ($required && ($value === null || $value === '')) {
            return false;
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return $value;
    }

    public function getDefaultValue(array $param): mixed
    {
        return $param['default'] ?? null;
    }
}
