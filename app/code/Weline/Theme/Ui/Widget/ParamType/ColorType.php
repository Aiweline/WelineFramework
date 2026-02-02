<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Ui\Widget\ParamType;

/**
 * 颜色类型参数 UI 组件
 * 
 * 渲染颜色选择器 + 文本输入框，支持 transparent 等特殊值
 */
class ColorType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'color';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $allowTransparent = $param['allow_transparent'] ?? true;
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '#000000';
        
        // 处理特殊颜色值（如 transparent）
        $colorPickerValue = $this->normalizeColorForPicker($currentValue);
        $textValue = (string)$currentValue;
        
        $inputHtml = '<div class="color-picker-wrapper">';
        
        // 颜色选择器
        $inputHtml .= '<input type="color" class="form-control-color" id="' . htmlspecialchars($fieldId) . '_picker" value="' . htmlspecialchars($colorPickerValue) . '" data-target="' . htmlspecialchars($fieldId) . '">';
        
        // 文本输入框
        $inputHtml .= '<input type="text" class="form-control" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($textValue) . '" placeholder="#000000">';
        
        // 透明按钮
        if ($allowTransparent) {
            $isTransparent = strtolower($textValue) === 'transparent';
            $btnClass = $isTransparent ? 'btn btn-sm btn-outline-secondary active' : 'btn btn-sm btn-outline-secondary';
            $inputHtml .= '<button type="button" class="' . $btnClass . ' btn-transparent" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('设为透明') . '">';
            $inputHtml .= '<i class="ri-checkbox-blank-line"></i>';
            $inputHtml .= '</button>';
        }
        
        $inputHtml .= '</div>';
        
        // 预设颜色
        $presets = $param['presets'] ?? [];
        if (!empty($presets)) {
            $inputHtml .= '<div class="color-presets">';
            foreach ($presets as $preset) {
                $inputHtml .= '<button type="button" class="color-preset-btn" style="background-color: ' . htmlspecialchars($preset) . ';" data-color="' . htmlspecialchars($preset) . '" data-target="' . htmlspecialchars($fieldId) . '" title="' . htmlspecialchars($preset) . '"></button>';
            }
            $inputHtml .= '</div>';
        }
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    /**
     * 将颜色值标准化为颜色选择器可用的格式
     */
    private function normalizeColorForPicker(string $color): string
    {
        $color = strtolower(trim($color));
        
        // 特殊值转为黑色
        if ($color === 'transparent' || $color === 'inherit' || $color === 'initial') {
            return '#000000';
        }
        
        // 已经是有效的 hex 颜色
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return $color;
        }
        
        // 3位 hex 转 6位
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $color, $matches)) {
            return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
        }
        
        // 命名颜色映射
        $namedColors = [
            'white' => '#ffffff',
            'black' => '#000000',
            'red' => '#ff0000',
            'green' => '#008000',
            'blue' => '#0000ff',
            'yellow' => '#ffff00',
            'orange' => '#ffa500',
            'purple' => '#800080',
            'gray' => '#808080',
            'grey' => '#808080',
        ];
        
        return $namedColors[$color] ?? '#000000';
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        if ($value === null || $value === '') {
            return true;
        }
        
        $value = strtolower(trim((string)$value));
        
        // 允许特殊值
        if (in_array($value, ['transparent', 'inherit', 'initial', 'currentcolor'])) {
            return true;
        }
        
        // 验证 hex 颜色
        if (preg_match('/^#[0-9a-f]{3}$/i', $value) || preg_match('/^#[0-9a-f]{6}$/i', $value) || preg_match('/^#[0-9a-f]{8}$/i', $value)) {
            return true;
        }
        
        // 验证 rgb/rgba
        if (preg_match('/^rgba?\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/i', $value)) {
            return true;
        }
        
        return false;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }
}
