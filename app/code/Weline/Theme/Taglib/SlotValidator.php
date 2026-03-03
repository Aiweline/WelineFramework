<?php

declare(strict_types=1);

/**
 * w:slot 标签验证服务
 * 
 * 负责验证 slot 标签的属性规范：
 * 1. id 必填验证
 * 2. id 唯一性验证（同一编译周期内）
 * 3. position 值有效性验证
 * 4. 属性互斥验证（exclusive 和 multiple）
 * 5. accept/reject 冲突警告
 * 
 * DEV 模式下会抛出致命错误或警告
 * 
 * @author Weline
 * @since 1.0.0
 */

namespace Weline\Theme\Taglib;

use Weline\Framework\View\Exception\TemplateException;

class SlotValidator
{
    /**
     * 允许的 position 值
     */
    private const VALID_POSITIONS = ['header', 'content', 'footer', 'sidebar'];
    
    /**
     * 验证所有属性
     * 
     * @param array $attrs 属性数组
     * @param string $file 模板文件路径
     * @param int $line 行号
     * @throws TemplateException
     */
    public static function validate(array $attrs, string $file, int $line): void
    {
        // 1. 验证 id 必填
        self::validateId($attrs, $file, $line);
        
        // 2. 验证 position 值（如果提供）
        if (isset($attrs['position']) && $attrs['position'] !== '') {
            self::validatePosition($attrs['position'], $file, $line);
        }
        
        // 3. 验证属性互斥
        self::validateMutualExclusive($attrs, $file, $line);
        
        // 4. 验证 accept/reject 冲突（警告）
        self::validateAcceptReject($attrs, $file, $line);
        
        // 5. 验证数值属性
        self::validateNumericAttributes($attrs, $file, $line);
    }
    
    /**
     * 验证 id 必填
     * 
     * @throws TemplateException
     */
    public static function validateId(array $attrs, string $file, int $line): void
    {
        if (!isset($attrs['id']) || trim((string)$attrs['id']) === '') {
            self::throwError(
                __('w:slot 标签缺少必填属性 id'),
                $file,
                $line,
                '<w:slot id="content" name="内容区域">...</w:slot>'
            );
        }
        
        // 验证 id 格式（只允许字母、数字、下划线、连字符）
        $id = trim((string)$attrs['id']);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $id)) {
            self::throwError(
                __('w:slot 标签 id 格式无效: "%{id}"，只允许字母开头，包含字母、数字、下划线、连字符', ['id' => $id]),
                $file,
                $line,
                '<w:slot id="main-content" name="主内容区">...</w:slot>'
            );
        }
    }
    
    /**
     * 验证 id 唯一性（抛出重复错误）
     * 
     * @throws TemplateException
     */
    public static function throwDuplicateError(string $id, string $existingLocation, string $currentLocation): void
    {
        if (!self::isDevMode()) {
            return;
        }
        
        $message = __('w:slot 标签 id 重复: "%{id}"', ['id' => $id]) . "\n" .
                   __('已存在于: %{location}', ['location' => $existingLocation]) . "\n" .
                   __('当前位置: %{location}', ['location' => $currentLocation]);
        
        throw new TemplateException($message);
    }
    
    /**
     * 验证 position 值
     * 
     * @throws TemplateException
     */
    public static function validatePosition(string $position, string $file, int $line): void
    {
        $position = trim($position);
        
        if (!in_array($position, self::VALID_POSITIONS, true)) {
            self::throwError(
                __('w:slot 标签 position 属性值无效: "%{value}"', ['value' => $position]) . "\n" .
                __('允许的值: %{values}', ['values' => implode(', ', self::VALID_POSITIONS)]),
                $file,
                $line,
                '<w:slot id="main" position="content">...</w:slot>'
            );
        }
    }
    
    /**
     * 验证属性互斥
     * exclusive="true" 和 multiple="true" 不能同时存在
     * 
     * @throws TemplateException
     */
    public static function validateMutualExclusive(array $attrs, string $file, int $line): void
    {
        $exclusive = self::toBool($attrs['exclusive'] ?? false);
        $multiple = self::toBool($attrs['multiple'] ?? false);
        
        if ($exclusive && $multiple) {
            self::throwError(
                __('w:slot 标签属性冲突 - exclusive 和 multiple 不能同时为 true'),
                $file,
                $line,
                '<w:slot id="logo" exclusive="true">...</w:slot>'
            );
        }
        
        // append 和 prepend 互斥
        $append = self::toBool($attrs['append'] ?? false);
        $prepend = self::toBool($attrs['prepend'] ?? false);
        
        if ($append && $prepend) {
            self::throwError(
                __('w:slot 标签属性冲突 - append 和 prepend 不能同时为 true'),
                $file,
                $line,
                '<w:slot id="content" append="true">...</w:slot>'
            );
        }
        
        // exclusive 和 append/prepend 互斥
        if ($exclusive && ($append || $prepend)) {
            self::throwError(
                __('w:slot 标签属性冲突 - exclusive 模式下不能使用 append 或 prepend'),
                $file,
                $line,
                '<w:slot id="logo" exclusive="true">...</w:slot>'
            );
        }
    }
    
    /**
     * 验证 accept/reject 冲突
     * 如果 accept 和 reject 包含相同的值，发出警告
     */
    public static function validateAcceptReject(array $attrs, string $file, int $line): void
    {
        if (!self::isDevMode()) {
            return;
        }
        
        $accept = isset($attrs['accept']) ? array_map('trim', explode(',', (string)$attrs['accept'])) : [];
        $reject = isset($attrs['reject']) ? array_map('trim', explode(',', (string)$attrs['reject'])) : [];
        
        // 过滤空值
        $accept = array_filter($accept);
        $reject = array_filter($reject);
        
        if (empty($accept) || empty($reject)) {
            return;
        }
        
        $conflicts = array_intersect($accept, $reject);
        
        if (!empty($conflicts)) {
            $id = $attrs['id'] ?? 'unknown';
            $conflictList = implode(', ', $conflicts);
            
            // 发出警告（不抛出异常）
            self::triggerWarning(
                __('w:slot id="%{id}" accept 和 reject 包含相同的部件类型: %{types}', [
                    'id' => $id,
                    'types' => $conflictList
                ]),
                $file,
                $line
            );
        }
    }
    
    /**
     * 验证数值属性
     * 
     * @throws TemplateException
     */
    public static function validateNumericAttributes(array $attrs, string $file, int $line): void
    {
        // 验证 max
        if (isset($attrs['max']) && $attrs['max'] !== '') {
            $max = $attrs['max'];
            if (!is_numeric($max) || (int)$max < -1) {
                self::throwError(
                    __('w:slot 标签 max 属性必须是 >= -1 的整数，当前值: "%{value}"', ['value' => $max]),
                    $file,
                    $line,
                    '<w:slot id="sidebar" max="5">...</w:slot>'
                );
            }
        }
        
        // 验证 min
        if (isset($attrs['min']) && $attrs['min'] !== '') {
            $min = $attrs['min'];
            if (!is_numeric($min) || (int)$min < 0) {
                self::throwError(
                    __('w:slot 标签 min 属性必须是 >= 0 的整数，当前值: "%{value}"', ['value' => $min]),
                    $file,
                    $line,
                    '<w:slot id="content" min="1">...</w:slot>'
                );
            }
        }
        
        // 验证 min <= max（如果两者都存在且 max != -1）
        if (isset($attrs['min']) && isset($attrs['max']) && 
            $attrs['min'] !== '' && $attrs['max'] !== '' && 
            (int)$attrs['max'] !== -1) {
            $min = (int)$attrs['min'];
            $max = (int)$attrs['max'];
            
            if ($min > $max) {
                self::throwError(
                    __('w:slot 标签 min (%{min}) 不能大于 max (%{max})', ['min' => $min, 'max' => $max]),
                    $file,
                    $line,
                    '<w:slot id="sidebar" min="1" max="5">...</w:slot>'
                );
            }
        }
    }
    
    /**
     * 抛出模板错误
     * 
     * @throws TemplateException
     */
    private static function throwError(string $message, string $file, int $line, string $example = ''): void
    {
        // 只在 DEV 模式下抛出详细错误
        if (!self::isDevMode()) {
            // 生产模式下记录日志但不中断
            w_log_error("[w:slot Error] {$message} in {$file}:{$line}");
            return;
        }
        
        $fullMessage = __('致命错误') . ": {$message}\n" .
                       __('文件: %{file}', ['file' => $file]) . "\n" .
                       __('行数: %{line}', ['line' => $line]);
        
        if ($example) {
            $fullMessage .= "\n" . __('示例: %{example}', ['example' => $example]);
        }
        
        throw new TemplateException($fullMessage);
    }
    
    /**
     * 触发警告（不中断执行）
     */
    private static function triggerWarning(string $message, string $file, int $line): void
    {
        if (!self::isDevMode()) {
            return;
        }
        
        $fullMessage = __('警告') . ": {$message}\n" .
                       __('文件: %{file}', ['file' => $file]) . "\n" .
                       __('行数: %{line}', ['line' => $line]);
        
        // 使用 trigger_error 发出警告
        trigger_error($fullMessage, E_USER_WARNING);
    }
    
    /**
     * 检查是否为 DEV 模式
     */
    private static function isDevMode(): bool
    {
        return defined('DEV') && DEV;
    }
    
    /**
     * 将值转换为布尔值
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }
        
        return (bool)$value;
    }
}
