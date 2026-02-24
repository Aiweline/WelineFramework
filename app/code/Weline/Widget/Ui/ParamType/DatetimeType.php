<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 日期时间类型参数 UI 组件
 */
class DatetimeType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'datetime';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $mode = $param['mode'] ?? 'datetime';
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        $required = $param['required'] ?? false;
        $format = $param['format'] ?? null;
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $inputType = match ($mode) {
            'date' => 'date',
            'time' => 'time',
            default => 'datetime-local',
        };
        $formattedValue = $this->formatValueForInput((string)$currentValue, $mode);
        $inputHtml = '<div class="w-param-datetime"><div class="w-param-input-group">';
        $inputHtml .= '<input type="' . $inputType . '" class="w-param-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($formattedValue) . '"';
        if ($min !== null) {
            $inputHtml .= ' min="' . htmlspecialchars($this->formatValueForInput($min, $mode)) . '"';
        }
        if ($max !== null) {
            $inputHtml .= ' max="' . htmlspecialchars($this->formatValueForInput($max, $mode)) . '"';
        }
        if ($required) {
            $inputHtml .= ' required';
        }
        $inputHtml .= '>';
        if (!$required && !empty($currentValue)) {
            $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-outline-secondary w-param-datetime-clear" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('清除') . '">×</button>';
        }
        $inputHtml .= '</div>';
        if ($mode === 'date' || $mode === 'datetime') {
            $inputHtml .= '<div class="w-param-datetime-shortcuts">';
            $inputHtml .= '<button type="button" class="w-param-btn" data-action="today" data-target="' . htmlspecialchars($fieldId) . '">' . __('今天') . '</button>';
            $inputHtml .= '<button type="button" class="w-param-btn" data-action="tomorrow" data-target="' . htmlspecialchars($fieldId) . '">' . __('明天') . '</button>';
            $inputHtml .= '<button type="button" class="w-param-btn" data-action="next_week" data-target="' . htmlspecialchars($fieldId) . '">' . __('下周') . '</button>';
            $inputHtml .= '</div>';
        }
        if ($format !== null) {
            $inputHtml .= '<small class="w-param-field-desc">' . sprintf(__('格式: %s'), $format) . '</small>';
        }
        $inputHtml .= '</div>';
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    private function formatValueForInput(string $value, string $mode): string
    {
        if (empty($value)) {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }
        return match ($mode) {
            'date' => date('Y-m-d', $timestamp),
            'time' => date('H:i', $timestamp),
            default => date('Y-m-d\TH:i', $timestamp),
        };
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        if ($value === null || $value === '') {
            return true;
        }
        if (strtotime((string)$value) === false) {
            return false;
        }
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        $timestamp = strtotime((string)$value);
        if ($min !== null && ($minTs = strtotime($min)) !== false && $timestamp < $minTs) {
            return false;
        }
        if ($max !== null && ($maxTs = strtotime($max)) !== false && $timestamp > $maxTs) {
            return false;
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $mode = $param['mode'] ?? 'datetime';
        $outputFormat = $param['output_format'] ?? null;
        $timestamp = strtotime((string)$value);
        if ($timestamp === false) {
            return $value;
        }
        if ($outputFormat !== null) {
            return date($outputFormat, $timestamp);
        }
        return match ($mode) {
            'date' => date('Y-m-d', $timestamp),
            'time' => date('H:i:s', $timestamp),
            default => date('Y-m-d H:i:s', $timestamp),
        };
    }
}
