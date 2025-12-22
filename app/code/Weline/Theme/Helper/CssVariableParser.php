<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

/**
 * CSS变量解析器
 * 
 * 用于解析CSS变量文件（_colors.css, _spacing.css等），提取变量定义、类型、分类等信息
 */
class CssVariableParser
{
    /**
     * 解析CSS变量文件
     * 
     * @param string $filePath 文件路径
     * @return array 变量数组，格式：[['name' => '--var-name', 'value' => 'value', 'type' => 'color', ...], ...]
     */
    public static function parseFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        return self::extractVariables($content, $filePath);
    }
    
    /**
     * 提取变量定义
     * 
     * @param string $cssContent CSS文件内容
     * @param string $filePath 文件路径（用于提取文件名）
     * @return array 变量数组
     */
    private static function extractVariables(string $cssContent, string $filePath): array
    {
        $variables = [];
        
        // 提取文件名（不含扩展名和前缀下划线）
        $fileName = basename($filePath, '.css');
        $fileName = ltrim($fileName, '_'); // 移除 _ 前缀
        
        // 提取文件级别的meta信息（从文件头部注释）
        $fileMeta = self::extractFileMeta($cssContent);
        
        // 按行处理，提取分类和变量
        $lines = explode("\n", $cssContent);
        $currentCategory = '其他';
        $currentDescription = '';
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            
            // 检查是否是分类注释（格式：/* ========== 分类名 ========== */）
            if (preg_match('/\/\*\s*={3,}\s*([^=]+)\s*={3,}\s*\*\//', $line, $categoryMatches)) {
                $currentCategory = trim($categoryMatches[1]);
                $currentDescription = '';
                continue;
            }
            
            // 检查是否是变量定义（格式：--var-name: value;）
            if (preg_match('/--([\w-]+)\s*:\s*([^;]+);/', $line, $varMatches)) {
                $varName = '--' . $varMatches[1];
                $varValue = trim($varMatches[2]);
                
                // 提取行内注释作为描述
                if (preg_match('/\/\*\s*(.+?)\s*\*\//', $line, $descMatches)) {
                    $currentDescription = trim($descMatches[1]);
                }
                
                // 识别变量类型
                $varType = self::detectVariableType($varName, $varValue);
                
                // 判断是否为颜色值
                $isColor = self::isColorValue($varValue);
                
                $variables[] = [
                    'variable_name' => $varName,
                    'variable_type' => $varType,
                    'default_value' => $varValue,
                    'category' => $currentCategory,
                    'description' => $currentDescription ?: self::generateDescription($varName, $varType),
                    'is_color' => $isColor,
                    'file' => $fileName,
                    'line' => $lineNum + 1
                ];
                
                // 重置描述（避免下一个变量使用）
                $currentDescription = '';
            }
        }
        
        return $variables;
    }
    
    /**
     * 提取文件级别的meta信息
     * 
     * @param string $cssContent CSS文件内容
     * @return array meta信息
     */
    private static function extractFileMeta(string $cssContent): array
    {
        $meta = [];
        
        // 提取 @meta.name
        if (preg_match('/@meta\.name\s*\{[^}]*default\s*=\s*"([^"]+)"/', $cssContent, $matches)) {
            $meta['name'] = $matches[1];
        }
        
        // 提取 @meta.description
        if (preg_match('/@meta\.description\s*\{[^}]*default\s*=\s*"([^"]+)"/', $cssContent, $matches)) {
            $meta['description'] = $matches[1];
        }
        
        // 提取 @meta.category
        if (preg_match('/@meta\.category\s*\{[^}]*default\s*=\s*"([^"]+)"/', $cssContent, $matches)) {
            $meta['category'] = $matches[1];
        }
        
        return $meta;
    }
    
    /**
     * 识别变量类型
     * 
     * @param string $varName 变量名（如 --color-primary）
     * @param string $value 变量值
     * @return string 变量类型（color, spacing, typography, shadow, border, other）
     */
    private static function detectVariableType(string $varName, string $value): string
    {
        // 从变量名推断类型
        $name = strtolower($varName);
        
        if (strpos($name, 'color-') === 2 || strpos($name, '--color') === 0) {
            return 'color';
        }
        if (strpos($name, 'spacing-') === 2 || strpos($name, 'space-') === 2 || strpos($name, '--spacing') === 0) {
            return 'spacing';
        }
        if (strpos($name, 'font-') === 2 || strpos($name, 'text-') === 2 || strpos($name, 'typography-') === 2 || 
            strpos($name, '--font') === 0 || strpos($name, '--text') === 0) {
            return 'typography';
        }
        if (strpos($name, 'shadow-') === 2 || strpos($name, '--shadow') === 0) {
            return 'shadow';
        }
        if (strpos($name, 'border-') === 2 || strpos($name, '--border') === 0) {
            return 'border';
        }
        
        // 从值推断类型
        if (self::isColorValue($value)) {
            return 'color';
        }
        if (preg_match('/^\d+(\.\d+)?(rem|px|em|%)$/', trim($value))) {
            return 'spacing';
        }
        
        return 'other';
    }
    
    /**
     * 判断是否为颜色值
     * 
     * @param string $value 变量值
     * @return bool
     */
    private static function isColorValue(string $value): bool
    {
        $value = trim($value);
        
        // HEX格式：#rrggbb 或 #rgb
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return true;
        }
        
        // RGB格式：rgb(r, g, b) 或 rgba(r, g, b, a)
        if (preg_match('/^rgba?\(/', $value)) {
            return true;
        }
        
        // HSL格式：hsl(h, s%, l%) 或 hsla(h, s%, l%, a)
        if (preg_match('/^hsla?\(/', $value)) {
            return true;
        }
        
        // 颜色关键字（如 red, blue等，但这里不包含，因为CSS变量通常不使用关键字）
        // 如果值包含var()，检查引用的变量是否为颜色
        if (strpos($value, 'var(') !== false) {
            // 无法确定，返回false，由变量名判断
            return false;
        }
        
        return false;
    }
    
    /**
     * 生成默认描述
     * 
     * @param string $varName 变量名
     * @param string $type 变量类型
     * @return string 描述
     */
    private static function generateDescription(string $varName, string $type): string
    {
        // 从变量名生成描述
        $name = str_replace(['--', '-'], ['', ' '], $varName);
        $name = ucwords($name);
        
        $typeMap = [
            'color' => '颜色',
            'spacing' => '间距',
            'typography' => '字体',
            'shadow' => '阴影',
            'border' => '边框',
            'other' => '变量'
        ];
        
        $typeName = $typeMap[$type] ?? '变量';
        
        return "{$typeName}：{$name}";
    }
    
    /**
     * 验证和规范化值
     * 
     * @param string $value 变量值
     * @param string $type 变量类型
     * @return string 规范化后的值
     */
    public static function normalizeValue(string $value, string $type): string
    {
        $value = trim($value);
        
        if ($type === 'color') {
            return self::normalizeColorValue($value);
        }
        
        if ($type === 'spacing') {
            return self::normalizeSpacingValue($value);
        }
        
        return $value;
    }
    
    /**
     * 规范化颜色值
     * 
     * @param string $value 颜色值
     * @return string 规范化后的颜色值
     */
    private static function normalizeColorValue(string $value): string
    {
        $value = trim($value);
        
        // HEX格式：转换为小写
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value, $matches)) {
            return '#' . strtolower($matches[1]);
        }
        
        // RGB/RGBA格式：规范化空格
        if (preg_match('/^rgba?\((.+)\)$/', $value, $matches)) {
            $params = preg_replace('/\s+/', ' ', trim($matches[1]));
            $prefix = strpos($value, 'rgba') === 0 ? 'rgba' : 'rgb';
            return $prefix . '(' . $params . ')';
        }
        
        // HSL/HSLA格式：规范化空格
        if (preg_match('/^hsla?\((.+)\)$/', $value, $matches)) {
            $params = preg_replace('/\s+/', ' ', trim($matches[1]));
            $prefix = strpos($value, 'hsla') === 0 ? 'hsla' : 'hsl';
            return $prefix . '(' . $params . ')';
        }
        
        return $value;
    }
    
    /**
     * 规范化间距值
     * 
     * @param string $value 间距值
     * @return string 规范化后的间距值
     */
    private static function normalizeSpacingValue(string $value): string
    {
        $value = trim($value);
        
        // 如果是var()引用，保持原样
        if (strpos($value, 'var(') === 0) {
            return $value;
        }
        
        // 匹配数字+单位格式
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(rem|px|em|%)$/', $value, $matches)) {
            return $matches[1] . $matches[2];
        }
        
        return $value;
    }
}
