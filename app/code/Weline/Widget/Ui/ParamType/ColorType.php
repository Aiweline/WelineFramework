<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

use Weline\Theme\Helper\ThemeUiColor;

/**
 * 颜色类型参数 UI 组件
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
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '#000000';
        $colorPickerValue = $this->normalizeColorForPicker((string)$currentValue);
        $textValue = (string)$currentValue;
        $inputHtml = '<div class="w-param-color">';
        $inputHtml .= '<input type="color" class="w-param-form-control-color" id="' . htmlspecialchars($fieldId) . '_picker" value="' . htmlspecialchars($colorPickerValue) . '" data-target="' . htmlspecialchars($fieldId) . '">';
        $inputHtml .= '<input type="text" class="w-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($textValue) . '" placeholder="#000000">';
        if ($allowTransparent) {
            $isTransparent = strtolower($textValue) === 'transparent';
            $btnClass = 'w-button w-param-btn-transparent';
            $inputHtml .= '<button type="button" class="' . $btnClass . '" data-tone="neutral" data-variant="outline" data-size="sm" data-state="' . ($isTransparent ? 'active' : 'idle') . '" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('设为透明') . '">□</button>';
        }
        $inputHtml .= '</div>';
        $presetSource = is_array($param['presets'] ?? null) && ($param['presets'] ?? []) !== []
            ? $param['presets']
            : ThemeUiColor::SEMANTIC_PRESETS;
        $presets = array_values(array_filter(array_map(
            fn (mixed $preset): ?string => $this->normalizeCssColorScalar($preset),
            $presetSource
        ), static fn (?string $preset): bool => $preset !== null));
        if ($presets !== []) {
            $inputHtml .= '<div class="w-param-color-presets">';
            foreach ($presets as $preset) {
                $safePreset = htmlspecialchars($preset, ENT_QUOTES, 'UTF-8');
                $inputHtml .= '<button type="button" class="w-param-color-preset" style="--w-param-preset-color:' . $safePreset . '" data-color="' . $safePreset . '" data-target="' . htmlspecialchars($fieldId) . '" title="' . $safePreset . '"></button>';
            }
            $inputHtml .= '</div>';
        }
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    private function normalizeColorForPicker(string $color): string
    {
        $color = strtolower(trim($color));
        if (in_array($color, ['transparent', 'inherit', 'initial'], true)) {
            return '#000000';
        }
        if (ThemeUiColor::isThemeVar($color)) {
            return '#315ee7';
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return $color;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $color, $matches)) {
            return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
        }
        $named = ['white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'yellow' => '#ffff00', 'orange' => '#ffa500', 'purple' => '#800080', 'gray' => '#808080', 'grey' => '#808080'];
        return $named[$color] ?? '#000000';
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
        if (in_array($value, ['transparent', 'inherit', 'initial', 'currentcolor'], true)) {
            return true;
        }
        if (preg_match('/^#[0-9a-f]{3}$/i', $value) || preg_match('/^#[0-9a-f]{6}$/i', $value) || preg_match('/^#[0-9a-f]{8}$/i', $value)) {
            return true;
        }
        if (preg_match('/^rgba?\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/i', $value)) {
            return true;
        }
        if (ThemeUiColor::isValid((string)$value)) {
            return true;
        }
        return false;
    }

    protected function normalizeCssColorScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $color = trim((string)$value);
        if ($color === '' || strlen($color) > 128 || preg_match('/[;"\'\\\\{}<>]/', $color)) {
            return null;
        }
        if (ThemeUiColor::isValid($color)) {
            return $color;
        }

        return null;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }
}
