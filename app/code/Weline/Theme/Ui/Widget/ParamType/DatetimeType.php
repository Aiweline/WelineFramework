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
 * 日期时间类型参数 UI 组件
 * 
 * 渲染日期时间选择器，支持 date、time、datetime 三种模式
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
        $mode = $param['mode'] ?? 'datetime'; // date, time, datetime
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        $required = $param['required'] ?? false;
        $format = $param['format'] ?? null; // 显示格式提示
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        
        // 确定输入类型
        $inputType = match ($mode) {
            'date' => 'date',
            'time' => 'time',
            default => 'datetime-local',
        };
        
        // 格式化值以适应 HTML5 输入
        $formattedValue = $this->formatValueForInput($currentValue, $mode);
        
        $inputHtml = '<div class="datetime-picker-wrapper">';
        
        // 输入框
        $inputHtml .= '<div class="input-group">';
        
        // 图标
        $icon = match ($mode) {
            'date' => 'ri-calendar-line',
            'time' => 'ri-time-line',
            default => 'ri-calendar-event-line',
        };
        $inputHtml .= '<span class="input-group-text"><i class="' . $icon . '"></i></span>';
        
        // 输入控件
        $inputHtml .= '<input type="' . $inputType . '" class="form-control" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($formattedValue) . '"';
        
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
        
        // 清除按钮
        if (!$required && !empty($currentValue)) {
            $inputHtml .= '<button type="button" class="btn btn-outline-secondary btn-clear-datetime" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('清除') . '">';
            $inputHtml .= '<i class="ri-close-line"></i>';
            $inputHtml .= '</button>';
        }
        
        $inputHtml .= '</div>';
        
        // 快捷按钮
        if ($mode === 'date' || $mode === 'datetime') {
            $inputHtml .= '<div class="datetime-shortcuts">';
            $inputHtml .= '<button type="button" class="btn btn-link btn-sm p-0 me-2" data-action="today" data-target="' . htmlspecialchars($fieldId) . '">' . __('今天') . '</button>';
            $inputHtml .= '<button type="button" class="btn btn-link btn-sm p-0 me-2" data-action="tomorrow" data-target="' . htmlspecialchars($fieldId) . '">' . __('明天') . '</button>';
            $inputHtml .= '<button type="button" class="btn btn-link btn-sm p-0" data-action="next_week" data-target="' . htmlspecialchars($fieldId) . '">' . __('下周') . '</button>';
            $inputHtml .= '</div>';
        }
        
        // 格式提示
        if ($format !== null) {
            $inputHtml .= '<small class="form-text text-muted">' . sprintf(__('格式: %s'), $format) . '</small>';
        }
        
        $inputHtml .= '</div>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    /**
     * 格式化值以适应 HTML5 日期时间输入
     */
    private function formatValueForInput(string $value, string $mode): string
    {
        if (empty($value)) {
            return '';
        }
        
        try {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return $value;
            }
            
            return match ($mode) {
                'date' => date('Y-m-d', $timestamp),
                'time' => date('H:i', $timestamp),
                default => date('Y-m-d\TH:i', $timestamp),
            };
        } catch (\Exception $e) {
            return $value;
        }
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        if ($value === null || $value === '') {
            return true;
        }
        
        $mode = $param['mode'] ?? 'datetime';
        
        // 尝试解析日期时间
        $timestamp = strtotime((string)$value);
        if ($timestamp === false) {
            return false;
        }
        
        // 验证范围
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        
        if ($min !== null) {
            $minTimestamp = strtotime($min);
            if ($minTimestamp !== false && $timestamp < $minTimestamp) {
                return false;
            }
        }
        
        if ($max !== null) {
            $maxTimestamp = strtotime($max);
            if ($maxTimestamp !== false && $timestamp > $maxTimestamp) {
                return false;
            }
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
