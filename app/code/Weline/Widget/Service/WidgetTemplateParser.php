<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\App\Env;
use Weline\Framework\View\Template;

/**
 * 部件模板解析器
 * 
 * 从模板文件的注释中解析部件元数据和参数定义
 * 
 * 支持的注释格式：
 * @widget.code {header-search}
 * @widget.name {Header 搜索框}
 * @widget.description {搜索框部件，支持热词和自动补全}
 * @widget.type {search}
 * @widget.area {frontend}
 * @widget.position {["header"]}
 * @widget.page_layouts {["*"]}
 * @widget.slot {search}
 * @widget.supports {["search","layout-header-search"]}
 * @widget.exclusive {true}
 * 
 * @param placeholder {default="搜索商品...",type="string",label="占位符文字"}
 * @param show_hot_words {default=true,type="bool",label="显示热搜词"}
 */
class WidgetTemplateParser
{
    private Template $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    /**
     * 解析模板文件，提取部件元数据
     * 
     * @param string $templatePath 模板路径（如 Weline_Theme::theme/frontend/widgets/search/header-search/default.phtml）
     * @return array|null 解析后的部件配置，失败返回 null
     */
    public function parse(string $templatePath): ?array
    {
        try {
            // 获取模板文件的真实路径
            $realPath = $this->template->getTemplateRealPath($templatePath);
            if (empty($realPath) || !file_exists($realPath)) {
                return null;
            }

            // 读取模板文件内容
            $content = file_get_contents($realPath);
            if ($content === false) {
                return null;
            }

            // 从路径推断默认值
            $pathDefaults = $this->inferFromPath($templatePath);

            // 解析注释
            $widgetMeta = $this->parseWidgetMeta($content);
            $params = $this->parseParams($content);

            // 合并：路径推断 < 注释定义
            $result = array_merge($pathDefaults, $widgetMeta);
            
            // 添加参数
            if (!empty($params)) {
                $result['params'] = $params;
            }

            // 添加模板路径
            $result['template'] = $templatePath;
            $result['template_real_path'] = $realPath;

            return $result;
        } catch (\Exception $e) {
            w_log_error("解析部件模板失败: {$templatePath}, 错误: " . $e->getMessage(), [], 'WidgetTemplateParser');
            return null;
        }
    }

    /**
     * 从模板路径推断部件的基本信息
     * 
     * 路径格式：Module_Name::theme/frontend/widgets/{type}/{code}/default.phtml
     * 
     * @param string $templatePath
     * @return array
     */
    private function inferFromPath(string $templatePath): array
    {
        $result = [
            'area' => 'frontend',
            'version' => '1.0.0',
        ];

        // 解析模块名
        if (preg_match('/^([A-Za-z0-9_]+)::/', $templatePath, $matches)) {
            $result['module'] = $matches[1];
        }

        // 解析 area（frontend/backend）
        if (str_contains($templatePath, '/backend/')) {
            $result['area'] = 'backend';
        }

        // 解析 type 和 code
        // 格式：.../widgets/{type}/{code}/...
        if (preg_match('/widgets\/([a-z_-]+)\/([a-z0-9_-]+)\//i', $templatePath, $matches)) {
            $result['type'] = $matches[1];
            $result['code'] = $matches[2];
        }

        return $result;
    }

    /**
     * 解析 @widget.* 注释
     * 
     * @param string $content 模板内容
     * @return array
     */
    private function parseWidgetMeta(string $content): array
    {
        $result = [];

        // 支持格式：
        // @widget.name {Header 搜索框}
        // @widget.position {["header", "content"]}
        // @widget.exclusive {true}
        // @widget.slots { "logo": {...}, "search": {...} }
        //
        // 需要支持值中出现嵌套花括号（例如 JSON 对象），
        // 因此不能简单用正则截取，改为手动扫描，按花括号计数来匹配结束位置。

        $offset = 0;
        $len = strlen($content);
        $pattern = '/@widget\.([a-z_]+)\s*\{/i';

        while ($offset < $len && preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $key = $match[1][0];
            $matchPos = $match[0][1];

            // 找到当前 key 后面的第一个 '{'
            $bracePos = strpos($content, '{', $matchPos);
            if ($bracePos === false) {
                $offset = $matchPos + strlen($match[0][0]);
                continue;
            }

            // 从第一个 '{' 开始进行花括号计数，找到成对的结束位置
            $level = 0;
            $i = $bracePos;
            for (; $i < $len; $i++) {
                $ch = $content[$i];
                if ($ch === '{') {
                    $level++;
                } elseif ($ch === '}') {
                    $level--;
                    if ($level === 0) {
                        break;
                    }
                }
            }

            if ($level !== 0) {
                // 花括号未能正常闭合，跳过本次匹配，防止死循环
                $offset = $bracePos + 1;
                continue;
            }

            // 提取大括号内部的内容（去掉最外层的一对花括号）
            $valueStart = $bracePos + 1;
            $valueLength = $i - $valueStart;
            if ($valueLength < 0) {
                $offset = $i + 1;
                continue;
            }

            $rawValue = trim(substr($content, $valueStart, $valueLength));

            if ($rawValue === '') {
                $offset = $i + 1;
                continue;
            }

            // 对于 slots 这种对象型配置，rawValue 形如 `"logo": {...}, "search": {...}`
            // 需要补上最外层的一对花括号后再交给 parseValue 作为 JSON 对象解析。
            if ($key === 'slots') {
                $valueForParse = '{' . $rawValue . '}';
            } else {
                $valueForParse = $rawValue;
            }

            $result[$key] = $this->parseValue($valueForParse);

            // 更新 offset，继续向后搜索
            $offset = $i + 1;
        }

        return $result;
    }

    /**
     * 解析 @param 注释
     * 
     * 格式：@param param_name {default="value",type="string",label="标签",options={key:"value"}}
     * 
     * @param string $content 模板内容
     * @return array
     */
    private function parseParams(string $content): array
    {
        $result = [];

        // 匹配 @param name {config} 格式
        $pattern = '/@param\s+([a-z_][a-z0-9_]*)\s*\{([^}]+)\}/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $paramName = $match[1];
                $configStr = trim($match[2]);
                
                // 解析参数配置
                $paramConfig = $this->parseParamConfig($configStr);
                if (!empty($paramConfig)) {
                    $result[$paramName] = $paramConfig;
                }
            }
        }

        return $result;
    }

    /**
     * 解析参数配置字符串
     * 
     * 格式：default="value",type="string",label="标签",options={key:"value",key2:"value2"}
     * 
     * @param string $configStr
     * @return array
     */
    private function parseParamConfig(string $configStr): array
    {
        $result = [];
        
        // 解析 key=value 对
        // 需要处理嵌套的 {} 和 ""
        $pos = 0;
        $len = strlen($configStr);
        
        while ($pos < $len) {
            // 跳过空白和逗号
            while ($pos < $len && (ctype_space($configStr[$pos]) || $configStr[$pos] === ',')) {
                $pos++;
            }
            
            if ($pos >= $len) break;
            
            // 读取 key
            $keyStart = $pos;
            while ($pos < $len && $configStr[$pos] !== '=' && !ctype_space($configStr[$pos])) {
                $pos++;
            }
            $key = substr($configStr, $keyStart, $pos - $keyStart);
            
            if (empty($key)) break;
            
            // 跳过空白和等号
            while ($pos < $len && (ctype_space($configStr[$pos]) || $configStr[$pos] === '=')) {
                $pos++;
            }
            
            if ($pos >= $len) break;
            
            // 读取 value
            $value = '';
            if ($configStr[$pos] === '"') {
                // 字符串值
                $pos++; // 跳过开始引号
                $valueStart = $pos;
                while ($pos < $len && $configStr[$pos] !== '"') {
                    if ($configStr[$pos] === '\\' && $pos + 1 < $len) {
                        $pos++; // 跳过转义字符
                    }
                    $pos++;
                }
                $value = substr($configStr, $valueStart, $pos - $valueStart);
                $value = stripslashes($value);
                $pos++; // 跳过结束引号
            } elseif ($configStr[$pos] === '{') {
                // 对象/数组值
                $braceCount = 1;
                $valueStart = $pos;
                $pos++;
                while ($pos < $len && $braceCount > 0) {
                    if ($configStr[$pos] === '{') $braceCount++;
                    elseif ($configStr[$pos] === '}') $braceCount--;
                    $pos++;
                }
                $value = substr($configStr, $valueStart, $pos - $valueStart);
                $value = $this->parseOptionsValue($value);
            } elseif ($configStr[$pos] === '[') {
                // 数组值
                $bracketCount = 1;
                $valueStart = $pos;
                $pos++;
                while ($pos < $len && $bracketCount > 0) {
                    if ($configStr[$pos] === '[') $bracketCount++;
                    elseif ($configStr[$pos] === ']') $bracketCount--;
                    $pos++;
                }
                $value = substr($configStr, $valueStart, $pos - $valueStart);
                $value = $this->parseValue($value);
            } else {
                // 简单值（数字、布尔等）
                $valueStart = $pos;
                while ($pos < $len && $configStr[$pos] !== ',' && !ctype_space($configStr[$pos])) {
                    $pos++;
                }
                $value = substr($configStr, $valueStart, $pos - $valueStart);
                $value = $this->parseValue($value);
            }
            
            $result[$key] = $value;
        }
        
        return $result;
    }

    /**
     * 解析 options 值
     * 
     * 格式：{key:"value",key2:"value2"}
     * 
     * @param string $value
     * @return array
     */
    private function parseOptionsValue(string $value): array
    {
        $result = [];
        
        // 移除外层花括号
        $value = trim($value, '{}');
        
        // 解析 key:value 对
        $pattern = '/([a-z0-9_]+)\s*:\s*"([^"]*)"/i';
        if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result[$match[1]] = $match[2];
            }
        }
        
        return $result;
    }

    /**
     * 解析值
     * 
     * @param string $value
     * @return mixed
     */
    private function parseValue(string $value)
    {
        $value = trim($value);
        
        // 布尔值
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // 数字
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }
        
        // JSON 数组
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // JSON 对象
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // 字符串
        return $value;
    }

    /**
     * 验证解析结果是否包含必需字段
     * 
     * @param array $config 解析结果
     * @return bool
     */
    public function validate(array $config): bool
    {
        // 必需字段：code, type, template
        $required = ['code', 'type', 'template'];
        
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * 生成部件名称（如果未定义）
     * 
     * @param array $config
     * @return string
     */
    public function generateName(array $config): string
    {
        if (!empty($config['name'])) {
            return $config['name'];
        }
        
        // 从 code 生成：header-search -> Header Search
        $code = $config['code'] ?? 'widget';
        return ucwords(str_replace(['-', '_'], ' ', $code));
    }
}
