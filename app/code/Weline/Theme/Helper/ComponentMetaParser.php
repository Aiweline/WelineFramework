<?php
/**
 * 组件 Meta 信息解析器
 * 
 * 用于解析组件文件中的 Meta 信息，特别是参数定义和默认值
 * 
 * @package Weline\Theme\Helper
 * @author Weline Framework
 */
namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Meta\Api\ParamDefinitionNormalizerInterface;

class ComponentMetaParser
{
    /**
     * 解析组件文件的 Meta 信息
     * 
     * @param string $filePath 组件文件路径
     * @return array Meta 信息数组，包含组件信息和参数定义
     */
    public static function parse(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [
                'component' => '',
                'description' => '',
                'params' => [],
                'meta' => [],
                'preview_login' => 0
            ];
        }
        
        $content = file_get_contents($filePath);
        
        $meta = [
            'component' => '',
            'description' => '',
            'params' => [],
            'meta' => [], // 存储所有 @meta:: 标记
            'preview_login' => 0 // 默认不需要登录
        ];
        
        // 提取组件名称
        if (preg_match('/@component\s+(\w+)/', $content, $matches)) {
            $meta['component'] = trim($matches[1]);
        } elseif (preg_match('/组件：(.+)/', $content, $matches)) {
            $meta['component'] = trim($matches[1]);
        } elseif (preg_match('/布局：(.+)/', $content, $matches)) {
            $meta['component'] = trim($matches[1]);
        }
        
        // 提取组件描述（从注释的第一段）
        if (preg_match('/\*\s+(.+?)\s*\n\s*\*\s*字段/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        
        // 解析所有 @meta:: 标记（支持层级结构，拥有 . 子元素则认为上层是组）
        if (preg_match_all('/@meta::([^\s]+)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $metaKey = trim($match[1]); // 如：theme.group.frontend.description
                $attributesStr = trim($match[2]); // 如：default="...",name="...",description="..."
                
                // 验证保留后缀：不允许在 metaKey 中使用 .lang、.value、.config、.info
                $reservedSuffixes = ['.lang', '.value', '.config', '.info'];
                foreach ($reservedSuffixes as $suffix) {
                    if (str_ends_with($metaKey, $suffix)) {
                        throw new \Exception("错误：Meta 定义中不允许使用保留后缀 '{$suffix}'。文件：{$filePath}，Meta键：{$metaKey}。这些后缀是系统保留的，用于读取时的特殊处理。");
                    }
                }
                
                // 解析属性键值对
                $attributes = self::parseAttributes($attributesStr, $filePath, $metaKey);
                
                // 构建层级结构
                $keys = explode('.', $metaKey);
                $current = &$meta['meta'];
                
                foreach ($keys as $key) {
                    // 验证键名：不允许使用保留后缀
                    foreach ($reservedSuffixes as $suffix) {
                        if (str_ends_with($key, $suffix)) {
                            throw new \Exception("错误：Meta 定义中不允许使用保留后缀 '{$suffix}'。文件：{$filePath}，Meta键：{$metaKey}，键名：{$key}。这些后缀是系统保留的，用于读取时的特殊处理。");
                        }
                    }
                    
                    if (!isset($current[$key])) {
                        $current[$key] = [];
                    }
                    $current = &$current[$key];
                }
                
                // 合并属性到当前节点
                $current = array_merge($current, $attributes);
            }
        }
        
        // 解析 @meta.name / @meta.cache.mode 格式（简化格式，等同于 @meta::…；支持点号层级）
        if (preg_match_all('/@meta\.([\w.]+)\s*\{([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $metaKey = trim($match[1]); // 如：name, cache.mode
                $attributesStr = trim($match[2]); // 如：default="...",name="...",description="..."
                
                // 验证保留后缀
                $reservedSuffixes = ['.lang', '.value', '.config', '.info'];
                foreach ($reservedSuffixes as $suffix) {
                    if (str_ends_with($metaKey, $suffix)) {
                        throw new \Exception("错误：Meta 定义中不允许使用保留后缀 '{$suffix}'。文件：{$filePath}，Meta键：{$metaKey}。这些后缀是系统保留的，用于读取时的特殊处理。");
                    }
                }
                
                // 解析属性键值对
                $attributes = self::parseAttributes($attributesStr, $filePath, $metaKey);
                
                // 点号键构建层级（与 @meta:: 一致），单段键仍落在 meta 根级
                $keys = explode('.', $metaKey);
                $current = &$meta['meta'];
                foreach ($keys as $index => $keyPart) {
                    if ($keyPart === '') {
                        continue;
                    }
                    if ($index === count($keys) - 1) {
                        if (!isset($current[$keyPart]) || !is_array($current[$keyPart])) {
                            $current[$keyPart] = [];
                        }
                        $current[$keyPart] = array_merge($current[$keyPart], $attributes);
                        break;
                    }
                    if (!isset($current[$keyPart]) || !is_array($current[$keyPart])) {
                        $current[$keyPart] = [];
                    }
                    $current = &$current[$keyPart];
                }
                unset($current);
            }
        }
        
        // 提取预览登录标记 @preview.login {default=0,...}
        if (preg_match('/@preview\.login\s*\{([^}]+)\}/i', $content, $matches)) {
            $attributes = self::parseAttributes($matches[1], $filePath, '@preview.login');
            if (isset($attributes['default'])) {
                $meta['preview_login'] = (int)$attributes['default'];
            }
        }
        
        // 提取参数定义 @param name {type=string, default=..., name=..., description=...}
        /** @var ParamDefinitionNormalizerInterface $normalizer */
        $normalizer = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ParamDefinitionNormalizerInterface::class);
        if (!$normalizer instanceof ParamDefinitionNormalizerInterface) {
            throw new \RuntimeException('Weline_Meta param normalizer provider is unavailable.');
        }
        $paramDefinitions = $normalizer->extractParamAnnotations($content);
        if (!empty($paramDefinitions)) {
            foreach ($paramDefinitions as $paramName => $definition) {
                $definition['param_name'] = $paramName;
                $definition['name_label'] = $definition['label'] ?? $definition['name'] ?? $paramName;
                $definition['name'] = $paramName;
                $meta['params'][] = $definition;
            }
        }
        
        return $meta;
    }
    
    /**
     * 解析属性字符串为数组
     * 支持格式：key=value, key2="value with spaces", key3=value3, key4={0:不需要登录,1:需要登录}
     * 
     * @param string $attributesStr 属性字符串
     * @param string $filePath 文件路径（用于错误提示）
     * @param string $metaKey Meta键（用于错误提示）
     * @return array 属性数组
     * @throws \Exception 如果使用了保留后缀
     */
    private static function parseAttributes(string $attributesStr, string $filePath = '', string $metaKey = ''): array
    {
        $attributes = [];
        
        // 保留后缀列表
        $reservedSuffixes = ['.lang', '.value', '.config', '.info'];
        
        // 先处理带引号的值，再处理不带引号的值
        $pattern = '/(\w+)=(?:"([^"]*)"|\{([^}]+)\}|([^,}]+))/';
        if (preg_match_all($pattern, $attributesStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim($match[1]);
                
                // 验证键名：不允许使用保留后缀
                foreach ($reservedSuffixes as $suffix) {
                    if (str_ends_with($key, $suffix)) {
                        throw new \Exception("错误：Meta 定义中不允许使用保留后缀 '{$suffix}' 作为属性键名。文件：{$filePath}，Meta键：{$metaKey}，属性键：{$key}。这些后缀是系统保留的，用于读取时的特殊处理。");
                    }
                }
                
                // 优先使用引号内的值，然后是花括号内的值，最后是不带引号的值
                // 注意：部分分组可能不存在，使用 null 合并运算符并统一转为字符串，避免 undefined index / trim(null) 警告
                $v2 = $match[2] ?? '';
                $v3 = $match[3] ?? '';
                $v4 = $match[4] ?? '';
                $rawValue = $v2 !== '' ? $v2 : ($v3 !== '' ? $v3 : $v4);
                $value = trim((string)$rawValue);
                
                // 处理特殊值
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                } elseif ($value === '[]') {
                    $value = [];
                } elseif (preg_match('/^\[.*\]$/', $value)) {
                    // 数组格式，保持原样，由调用者处理
                    // 例如：[1,2,3] 或 ['item1','item2']
                }
                
                $attributes[$key] = $value;
            }
        }
        
        return $attributes;
    }
    
    /**
     * 生成参数默认值代码
     * 
     * 根据 Meta 信息中的参数定义，生成获取参数值的 PHP 代码
     * 
     * @param array $param 参数定义数组
     * @param string $templateVar 模板变量名，默认为 '$this'
     * @return string PHP 代码字符串
     */
    public static function generateDefaultValueCode(array $param, string $templateVar = '$this'): string
    {
        $name = $param['name'];
        $type = $param['type'] ?? 'mixed';
        $default = $param['default'] ?? null;
        
        // 如果没有默认值，根据类型生成默认值
        if ($default === null) {
            switch ($type) {
                case 'string':
                    $default = "''";
                    break;
                case 'int':
                    $default = '0';
                    break;
                case 'float':
                    $default = '0.0';
                    break;
                case 'bool':
                    $default = 'false';
                    break;
                case 'array':
                    $default = '[]';
                    break;
                default:
                    $default = 'null';
            }
        }
        
        // 处理 PHP 变量表达式（包含 $ 符号）
        $isPhpExpression = strpos($default, '$') !== false;
        
        // 生成代码
        if ($isPhpExpression) {
            // PHP 变量表达式，直接使用
            $code = sprintf(
                '$%s = %s->getData(\'%s\') ?? %s;',
                $name,
                $templateVar,
                $name,
                $default
            );
        } else {
            // 字面量默认值
            $code = sprintf(
                '$%s = %s->getData(\'%s\') ?? %s;',
                $name,
                $templateVar,
                $name,
                $default
            );
        }
        
        // 添加类型转换
        if ($type !== 'mixed' && !$isPhpExpression) {
            switch ($type) {
                case 'int':
                    $code = sprintf('$%s = (int)(%s->getData(\'%s\') ?? %s);', $name, $templateVar, $name, $default);
                    break;
                case 'float':
                    $code = sprintf('$%s = (float)(%s->getData(\'%s\') ?? %s);', $name, $templateVar, $name, $default);
                    break;
                case 'bool':
                    $code = sprintf('$%s = (bool)(%s->getData(\'%s\') ?? %s);', $name, $templateVar, $name, $default);
                    break;
                case 'array':
                    $code = sprintf('$%s = (array)(%s->getData(\'%s\') ?? %s);', $name, $templateVar, $name, $default);
                    break;
                case 'string':
                    $code = sprintf('$%s = (string)(%s->getData(\'%s\') ?? %s);', $name, $templateVar, $name, $default);
                    break;
            }
        }
        
        return $code;
    }
    
    /**
     * 生成所有参数的默认值代码
     * 
     * @param array $params 参数定义数组
     * @param string $templateVar 模板变量名
     * @return array 代码数组，键为参数名，值为代码字符串
     */
    public static function generateAllDefaultValueCodes(array $params, string $templateVar = '$this'): array
    {
        $codes = [];
        foreach ($params as $param) {
            $codes[$param['name']] = self::generateDefaultValueCode($param, $templateVar);
        }
        return $codes;
    }
    
    /**
     * 验证参数值是否符合类型要求
     * 
     * @param mixed $value 参数值
     * @param string $type 参数类型
     * @return bool 是否符合类型要求
     */
    public static function validateType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'int':
                return is_int($value);
            case 'float':
                return is_float($value) || is_int($value);
            case 'bool':
                return is_bool($value);
            case 'array':
                return is_array($value);
            case 'object':
                return is_object($value);
            case 'callable':
                return is_callable($value);
            case 'mixed':
                return true;
            default:
                return true;
        }
    }
    
    /**
     * 格式化 Meta 信息为可读的文档格式
     * 
     * @param array $meta Meta 信息数组
     * @return string 格式化的文档字符串
     */
    public static function formatAsDocumentation(array $meta): string
    {
        $doc = "## {$meta['component']}\n\n";
        $doc .= "{$meta['description']}\n\n";
        
        if (!empty($meta['params'])) {
            $doc .= "### 参数列表\n\n";
            $doc .= "| 参数名 | 类型 | 必填 | 默认值 | 描述 |\n";
            $doc .= "|--------|------|------|--------|------|\n";
            
            foreach ($meta['params'] as $param) {
                $name = $param['name'];
                $type = $param['type'];
                $required = $param['required'] ? '是' : '否';
                $default = $param['default'] ?? '-';
                $description = $param['description'];
                
                // 转义 Markdown 特殊字符
                $default = str_replace(['|', '`'], ['\\|', '\\`'], $default);
                $description = str_replace(['|', '`'], ['\\|', '\\`'], $description);
                
                $doc .= "| `$name` | `$type` | $required | `$default` | $description |\n";
            }
        }
        
        return $doc;
    }
}
