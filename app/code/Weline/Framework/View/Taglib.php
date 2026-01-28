<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\App\Debug;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block\Csrf;
use Weline\Framework\View\Exception\TemplateException;
use Weline\Hook\HookData;

class Taglib
{
    // PHP 标签常量，避免在回调函数中重复定义
    private const PHP_OPEN_TAG = '<' . '?';
    private const PHP_CLOSE_TAG = '?' . '>';
    private const PHP_ECHO_TAG = '<' . '?' . '=';
    
    private const STAGE_COMPILE_TIME = 'COMPILE_TIME';
    private const STAGE_RUNTIME = 'RUNTIME';
    private const HTML_VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];
    
    /**
     * 性能优化：缓存变量解析结果
     */
    private static array $varParserCache = [];
    
    /**
     * 性能优化：缓存 Hook 检查结果
     */
    private static array $hookCheckCache = [];
    
    /**
     * 性能优化：缓存编译后的正则表达式
     */
    private static array $compiledRegexCache = [];
    
    public const operators_symbols = [
        # 比较
        '>',
        '<',
        '!==',
        '===',
        '==',
        '!=',
        '<>',
        '>=',
        '<=>',
        '<=',
        # 逻辑
        '&&',
        '||',
        '|',
        '!',
        ' and ',
        ' or ',
        ' xor ',
        # 算数运算
        '**',
        '%',
        '/',
        '*',
        '-',
        '+',
        # 位运算
        '<<',
        '>>',
        '&',
        '^^',
        '^',
        '|',
        # 赋值运算
        '=',
        '+=',
        '-=',
        '*=',
        '/=',
        '%=',
        '<<=',
        '>>=',
        '&=',
        '^^=',
        '^=',
        '|='
    ];

    public const operators_symbols_to_lang = [
        '||' => ' or ',
        '&&' => ' and ',
//        '|'=>' or ', 已当做过滤器使用
        '&' => ' and ',
        'xor' => ' xor ',
        ' neq ' => ' !== ',
        ' eq ' => ' == ',
        ' gt ' => ' > ',
        ' lt ' => ' < ',
        ' gte ' => ' >= ',
        ' lte ' => ' <= '
    ];

    public const special_lang_symbols = [
        'null', 'and', 'or', 'xor', '||', 'neq', 'eq', 'gt', 'lt', 'gte', 'lte'
    ];

    public function checkFilter(string $name, string $filter = '|', $default = '\'\''): array
    {
        if (str_contains($name, PHP_EOL)) {
            $name = str_replace(array("\r\n", "\r", "\n", "\t", ' '), '', $name);
        }
        if (str_contains($name, $filter)) {
            // 排除逻辑操作符 || 和 &&，它们不是默认值分隔符
            // 使用正则表达式匹配单个 | 而不是 || 或 |=
            // 匹配模式：| 前面不是 | 或 =，后面可以是引号（默认值可能是字符串）
            $pattern = '/(?<![\|=])' . preg_quote($filter, '/') . '(?![\|])/';
            if (preg_match($pattern, $name)) {
                $name_arr = explode('|', $name, 2); // 只分割第一个 |，避免分割 ||
                $name = $name_arr[0];
                if (isset($name_arr[1])) {
                    if (w_get_string_between_quotes($name_arr[1])) {
                        $default = $name_arr[1];
                    } else {
                        $default = $this->varParser($name_arr[1]);
                    }
                }
            }
        }
        return [$name, $default];
    }

    public function checkVar(string $name): string
    {
        if (str_starts_with($name, '$')) {
            //            return '('.$name.'??"")';
            return $name;
        }
        # 有字母的，且不是字符串，不存在特殊字符内的，可以加$
        if (preg_match('/^[a-zA-Z|\|\|]/', $name)) {
            if (!in_array($name, self::special_lang_symbols) and !str_starts_with($name, '"') and !str_starts_with($name, "'")) {
                $name = $name ? '$' . $name : $name;
            }
        }
        return $name;
    }

    public function varParser(string $name): string
    {
        // 性能优化：检查缓存
        if (isset(self::$varParserCache[$name])) {
            return self::$varParserCache[$name];
        }
        
        $name_str = '';
        # 处理过滤器
        list($originalName, $default) = $this->checkFilter($name);
        # 去除空白以及空格
        $originalName = $this->checkVar($originalName);

        # 处理转行变量
        $originalName = preg_replace('/ {4,}/', '', $originalName);

        # 单双引号包含的字符串不解析
        $exclude_names = w_get_string_between_quotes($originalName);

        foreach ($exclude_names as $key => $exclude_name) {
            $originalName = str_replace($exclude_name, 'w_var_str' . $key, $originalName);
        }

        // 性能优化：缓存编译后的正则表达式
        static $operatorPattern = '/(?<![\-\>()\s])\s*([><=!]={1,3}+|&&|\|\|)\s*(?![()\s])/';
        $originalName = preg_replace($operatorPattern, ' $1 ', $originalName);

        foreach ($exclude_names as $key => $exclude_name) {
            $originalName = str_replace('w_var_str' . $key, $exclude_name, $originalName);
        }
        
        $names = explode(' ', $originalName);
        foreach ($names as $name_key => $var) {
            # 排除字符串
            if (!str_contains($var, '"') && !str_contains($var, '\'')) {
                $var = $this->checkVar($var);
            }
            $pieces = explode('.', $var);
            $has_piece = false;
            $pieceCount = count($pieces);
            if ($pieceCount > 1) {
                $name_str .= '(';
                $has_piece = true;
            }
            foreach ($pieces as $key => $piece) {
                if (0 !== $key) {
                    if (str_contains($piece, '$')) {
                        $piece = '[' . $this->varParser(implode('.', $pieces)) . ']';
                        $name_str .= $piece;
                        break;
                    } else {
                        $piece = '[\'' . $piece . '\']';
                    }
                }
                $name_str .= $piece;
                unset($pieces[$key]);
            }
            // 如果有嵌套属性，需要闭合括号并添加默认值
            if ($has_piece) {
                $name_str = "{$name_str}??{$default}) ";
            } else {
                // 检查是否是操作符（||, &&, ==, !=, >, <, >=, <= 等）
                $isOperator = in_array(trim($var), ['||', '&&', '==', '!=', '!==', '===', '>', '<', '>=', '<=', 'or', 'and', 'xor']);
                if ($isOperator) {
                    // 操作符不需要添加默认值，直接添加空格
                    $name_str = $name_str . ' ';
                } else {
                    // 对于简单变量，如果提供了默认值，也要添加默认值
                    if ($default !== '\'\'') {
                        $name_str = "{$name_str}??{$default} ";
                    } else {
                        $name_str = $name_str . ' ';
                    }
                }
            }
        }

        // 替换操作符
        foreach (self::operators_symbols_to_lang as $item) {
            if (str_contains($name_str, $item)) {
                $name_str = str_replace($item, ' ' . $item . ' ', $name_str);
            }
        }

        // 缓存结果
        self::$varParserCache[$name] = $name_str;
        
        return $name_str;
    }


    /**
     * 使用 PHP tokenizer 统一解析 PHP 代码片段，提取纯表达式
     * 这是一个静态方法，供所有 Taglib callback 使用，避免重复解析
     * 
     * @param string $value 属性值（可能是 PHP 代码片段，如 PHP_ECHO_TAG 包裹的表达式，或普通字符串）
     * @return array ['is_php' => bool, 'expression' => string] 
     *               - is_php: 是否是 PHP 代码片段
     *               - expression: 如果是 PHP 代码，返回提取的纯表达式；否则返回原值
     */
    public static function parsePhpCodeToken(string $value): array
    {
        // 检查是否以 PHP 短标签开始（PHP_ECHO_TAG 或 PHP_OPEN_TAG）
        if (strpos($value, self::PHP_ECHO_TAG) !== 0 && strpos($value, self::PHP_OPEN_TAG) !== 0) {
            return ['is_php' => false, 'expression' => $value];
        }
        
        // 尝试使用 tokenizer 解析
        try {
            // 移除 PHP 标签，获取纯代码
            $code = $value;
            
            // 移除开头的 PHP_ECHO_TAG 或 PHP_OPEN_TAG
            if (strpos($code, self::PHP_ECHO_TAG) === 0) {
                $code = substr($code, 3);
            } elseif (strpos($code, self::PHP_OPEN_TAG) === 0) {
                $code = substr($code, 2);
            }
            
            // 移除结尾的 PHP_CLOSE_TAG 和可能的分号
            // 先移除尾随空白
            $code = rtrim($code);
            $codeLen = strlen($code);
            
            // 使用正则表达式更准确地移除 PHP_CLOSE_TAG 和可能的分号
            // 匹配模式：可能的分号 + PHP_CLOSE_TAG 或单独的 PHP_CLOSE_TAG
            $code = preg_replace('/;?\s*' . preg_quote(self::PHP_CLOSE_TAG, '/') . '\s*$/', '', $code);
            $code = rtrim($code);
            
            // 再次检查并移除可能残留的分号
            if (substr($code, -1) === ';') {
                $code = substr($code, 0, -1);
                $code = rtrim($code);
            }
            
            $code = trim($code);
            
            // 如果代码为空，不是有效的 PHP 代码
            if (empty($code)) {
                return ['is_php' => false, 'expression' => $value];
            }
            
            // 尝试 tokenize 来验证是否是有效的 PHP 代码
            // 包装在 PHP_OPEN_TAG . 'php' 和 PHP_CLOSE_TAG 中以便 tokenize
            $wrappedCode = self::PHP_OPEN_TAG . 'php ' . $code . ' ' . self::PHP_CLOSE_TAG;
            $tokens = @token_get_all($wrappedCode);
            
            // 检查是否包含有效的 PHP tokens（不仅仅是字符串）
            $hasValidPhpTokens = false;
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $tokenType = $token[0];
                    // 跳过 T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE
                    if (!in_array($tokenType, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE])) {
                        $hasValidPhpTokens = true;
                        break;
                    }
                }
            }
            
            if ($hasValidPhpTokens) {
                // 移除可能的外层括号（如果整个表达式被括号包裹）
                $code = preg_replace('/^\((.*)\)$/', '$1', $code);
                return ['is_php' => true, 'expression' => trim($code)];
            }
        } catch (\Throwable $e) {
            // 如果解析失败，当作普通字符串处理
        }
        
        return ['is_php' => false, 'expression' => $value];
    }

    /**
     * 使用 tokenizer 解析 HTML 标签属性
     * 可以准确识别包含 PHP 代码的属性值边界
     * 
     * @param string $rawAttributes 原始属性字符串
     * @return array 解析后的属性数组 [attrName => attrValue]
     */
    private function parseAttributesWithTokenizer(string $rawAttributes): array
    {
        $attributes = [];
        $length = strlen($rawAttributes);
        $i = 0;
        
        while ($i < $length) {
            // 跳过空白字符
            while ($i < $length && preg_match('/\s/', $rawAttributes[$i])) {
                $i++;
            }
            
            if ($i >= $length) {
                break;
            }
            
            // 匹配属性名
            if (!preg_match('/^(\S+?)=/', substr($rawAttributes, $i), $nameMatch)) {
                $i++;
                continue;
            }
            
            $attrName = trim($nameMatch[1]);
            $i += strlen($nameMatch[0]);
            
            // 跳过等号后的空白
            while ($i < $length && preg_match('/\s/', $rawAttributes[$i])) {
                $i++;
            }
            
            if ($i >= $length) {
                break;
            }
            
            // 检测引号类型
            $quote = $rawAttributes[$i];
            if ($quote !== '"' && $quote !== "'") {
                $i++;
                continue;
            }
            
            $i++; // 跳过开始引号
            $valueStart = $i;
            
            // 先检查属性值是否以 PHP 代码开始，如果是，需要特殊处理
            $peekAhead = substr($rawAttributes, $i, 10); // 向前查看最多10个字符
            $isPhpValue = (strpos($peekAhead, self::PHP_ECHO_TAG) === 0 || strpos($peekAhead, self::PHP_OPEN_TAG) === 0);
            
            $value = '';
            $inPhpTag = false;
            $phpTagDepth = 0;
            
            // 使用状态机解析属性值，识别 PHP 标签
            while ($i < $length) {
                $char = $rawAttributes[$i];
                $nextChar = ($i + 1 < $length) ? $rawAttributes[$i + 1] : '';
                $nextNextChar = ($i + 2 < $length) ? $rawAttributes[$i + 2] : '';
                
                // 检测 PHP 开始标签（PHP_OPEN_TAG 或 PHP_ECHO_TAG）
                if ($char === '<' && $nextChar === '?') {
                    if ($nextNextChar === '=') {
                        $value .= self::PHP_ECHO_TAG;
                        $i += 3;
                    } else {
                        $value .= self::PHP_OPEN_TAG;
                        $i += 2;
                    }
                    $inPhpTag = true;
                    $phpTagDepth = 1;
                    continue;
                }
                
                // 检测 PHP 结束标签（必须在 PHP 标签内）
                // 必须精确匹配 PHP_CLOSE_TAG，不能只是 ? 或 >
                if ($inPhpTag && $char === '?' && $nextChar === '>') {
                    $value .= self::PHP_CLOSE_TAG; // 添加完整的 PHP_CLOSE_TAG
                    $i += 2; // 跳过 ? 和 > 两个字符
                    $phpTagDepth--;
                    if ($phpTagDepth === 0) {
                        $inPhpTag = false;
                        // PHP 标签已结束，继续查找结束引号
                        continue;
                    }
                    continue;
                }
                
                // 在 PHP 标签内，如果遇到单独的 ? 但下一个不是 >，也要保留
                // 这确保不会误判 PHP 结束标签
                
                // 检测结束引号（不在 PHP 标签内时）
                if (!$inPhpTag && $char === $quote) {
                    // 检查是否是转义的引号
                    if ($i > 0 && $rawAttributes[$i - 1] === '\\') {
                        $value .= $char;
                        $i++;
                        continue;
                    }
                    // 找到结束引号
                    $i++; // 跳过结束引号
                    break;
                }
                
                // 在 PHP 标签内时，所有字符都要保留（包括 ? 和 >，除非是完整的 PHP_CLOSE_TAG）
                // 如果当前是 ? 但下一个不是 >，或者当前是 > 但上一个不是 ?，都要保留
                $value .= $char;
                $i++;
            }
            
            if (!empty($attrName) && !empty($value)) {
                $attributes[$attrName] = $value;
            }
        }
        
        return $attributes;
    }

    /**
     * 静态缓存：在同一个请求中缓存已收集的标签配置
     * 避免重复的事件分发和标签类实例化
     */
    private static ?array $cachedTags = null;

    public function getTags(Template $template, string $fileName = '', $content = ''): array
    {
        // 优化：使用静态缓存，避免同一请求内重复收集标签
        if (self::$cachedTags !== null) {
            return self::$cachedTags;
        }
        
        $tags = [
            'php' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => self::PHP_OPEN_TAG . 'php ',
                            'tag-end' => self::PHP_CLOSE_TAG,
                            default => self::PHP_OPEN_TAG . 'php ' . ($tag_data[1] ?? '') . ' ' . self::PHP_CLOSE_TAG
                        };
                    }
            ],
            'include' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => self::PHP_OPEN_TAG . 'php include(',
                            'tag-end' => ');' . self::PHP_CLOSE_TAG,
                            default => self::PHP_OPEN_TAG . 'php include(' . ($tag_data[1] ?? '') . ');' . self::PHP_CLOSE_TAG
                        };
                    }
            ],
            'var' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag()':
                            case '@tag{}':
                                $var_name = $this->varParser($tag_data[1]);
                                return self::PHP_OPEN_TAG . '=' . $var_name . self::PHP_CLOSE_TAG;
                            default:
                                $var_name = $this->varParser($this->checkVar($tag_data[2]));
                                return self::PHP_OPEN_TAG . '=' . $var_name . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            'pp' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=p(' . $var_name . ')' . self::PHP_CLOSE_TAG;
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=p(' . $var_name . ')' . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            'dd' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=dd(' . $var_name . ')' . self::PHP_CLOSE_TAG;
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=dd(' . $var_name . ')' . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            'count' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=' . $var_name . '?count(' . $var_name . '):0' . self::PHP_CLOSE_TAG;
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return self::PHP_OPEN_TAG . '=' . $var_name . '?count(' . $var_name . '):0' . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            
            'hook' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        // 优化：使用静态缓存 HookReader 实例，避免重复实例化（在函数最外层声明）
                        static $cachedHookReader = null;
                        
                        // 处理成对标签的情况（支持 else）
                        if ($tag_key === 'tag') {
                            $content = $tag_data[2] ?? '';
                            
                            // 检查是否有 else 标签（支持多种格式）
                            $else_pattern = '/<(?:w:)?else\s*\/?>/i';
                            $has_else = preg_match($else_pattern, $content, $else_matches, PREG_OFFSET_CAPTURE);
                            
                            if ($has_else && isset($else_matches[0]) && is_array($else_matches[0]) && isset($else_matches[0][1])) {
                                // 分割内容：else 之前是 hook 名称，之后是 else 内容
                                $else_pos = is_int($else_matches[0][1]) ? $else_matches[0][1] : (int)$else_matches[0][1];
                                $else_match_str = $else_matches[0][0] ?? '';
                                $hook_name = trim(substr($content, 0, $else_pos));
                                // 移除 hook 名称中的所有空白字符（包括换行符、制表符等）
                                $hook_name = preg_replace('/\s+/', '', $hook_name);
                                $else_content = substr($content, $else_pos + strlen($else_match_str));
                            } else {
                                // 没有 else 标签的情况
                                // 支持简单格式：<w:hook>HookName</w:hook>
                                $trimmed_content = trim($content);
                                
                                // 检查是否包含 HTML 标签或 PHP 代码
                                $html_pos = strpos($content, '<');
                                $php_pos = strpos($content, self::PHP_OPEN_TAG);
                                
                                if ($html_pos === false && $php_pos === false) {
                                    // 没有 HTML 标签和 PHP 代码，直接作为 hook 名称处理
                                    // 移除所有空白字符（包括换行符、制表符等），只保留 hook 名称
                                    $hook_name = preg_replace('/\s+/', '', $trimmed_content);
                                    $else_content = '';
                                } else {
                                    // 可能内容中混入了 HTML 或其他内容
                                    // 找到第一个 HTML 标签或 PHP 代码的位置，在那之前提取 hook 名称
                                    $min_pos = false;
                                    if ($html_pos !== false) {
                                        $min_pos = $html_pos;
                                    }
                                    if ($php_pos !== false) {
                                        $min_pos = ($min_pos === false) ? $php_pos : min($min_pos, $php_pos);
                                    }
                                    if ($min_pos !== false) {
                                        $hook_name = trim(substr($content, 0, $min_pos));
                                        // 移除 hook 名称中的空白字符
                                        $hook_name = preg_replace('/\s+/', '', $hook_name);
                                        $else_content = substr($content, $min_pos);
                                    } else {
                                        // 如果没找到 HTML 或 PHP 标签，尝试提取 hook 名称（在遇到非合法字符前停止）
                                        $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $trimmed_content);
                                        $hook_name = preg_replace('/\s+/', '', $hook_name);
                                        $else_content = '';
                                    }
                                }
                            }
                            
                            // 统一清理 hook 名称，移除可能混入的 PHP 代码标签和 HTML 标签
                            $hook_name = preg_replace('/<\?php\s*else\s*:?\s*' . self::PHP_CLOSE_TAG . '/i', '', $hook_name);
                            $hook_name = preg_replace('/<\?=\s*else\s*' . self::PHP_CLOSE_TAG . '/i', '', $hook_name);
                            // 移除可能混入的 HTML 标签（防止 else 内容被错误包含）
                            $hook_name = preg_replace('/<[^>]+>/', '', $hook_name);
                            // 移除可能混入的 PHP 代码
                            $hook_name = preg_replace('/<\?[^?]+' . self::PHP_CLOSE_TAG . '/', '', $hook_name);
                            // 只保留 hook 名称（在遇到非合法字符前停止，防止包含后续内容）
                            $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                            $hook_name = trim($hook_name);
                            // 检查 hook 是否存在
                            $hook_exists = false;
                            $hook_has_files = false;
                            
                            try {
                                // 使用 HookData 检查 hook 是否存在
                                $hook_exists = \Weline\Hook\HookData::hookExists($hook_name);
                                // 检查 hook 是否有实现文件
                                if ($hook_exists) {
                                    try {
                                        // 使用缓存的 HookReader 实例
                                        if ($cachedHookReader === null) {
                                            $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                                        }
                                        $hookReader = $cachedHookReader;
                                        $hookReader->setPath($hook_name);
                                        $hook_files = $hookReader->getFileList();
                                        $hook_has_files = !empty($hook_files);
                                    } catch (\Throwable $e) {
                                        // 如果检查失败，假设有文件（运行时处理）
                                        $hook_has_files = true;
                                    }
                                }
                            } catch (\Throwable $e) {
                                // HookData 不可用时，继续执行
                            }
                            
                            // 如果 hook 存在且有实现文件，返回 hook 调用代码
                            if ($hook_exists && $hook_has_files) {
                                // 在开发环境下，检查 hook 是否有规约
                                if (defined('DEV') && DEV) {
                                    try {
                                        $hookRegistry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Hook\HookRegistry::class);
                                        
                                        // 在开发环境下，如果 generated/hooks.php 不存在，提示需要运行 setup:upgrade
                                        $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
                                        if (!file_exists($registryFile)) {
                                            throw new \Exception(
                                                sprintf(
                                                    'Hook 注册表文件不存在，请先运行 php bin/w setup:upgrade 命令收集注册表信息。'
                                                )
                                            );
                                        }
                                        
                                        // 如果钩子没有规约，提示需要运行 setup:upgrade
                                        if (!$hookRegistry->hasSpec($hook_name)) {
                                            // 重新检查
                                            if (!$hookRegistry->hasSpec($hook_name)) {
                                                // 解析模块名
                                                $moduleName = '';
                                                if (str_contains($hook_name, '::')) {
                                                    // 新格式：ModuleName::area::type::component::position
                                                    $parts = explode('::', $hook_name);
                                                    $moduleName = $parts[0] ?? '';
                                                } else {
                                                    // 简单格式：尝试从注册表中查找所属模块
                                                    $hookModule = $hookRegistry->getHookModule($hook_name);
                                                    if ($hookModule) {
                                                        $moduleName = $hookModule;
                                                    } else {
                                                        // 如果找不到，提示用户需要定义规约
                                                        $moduleName = '相关模块';
                                                    }
                                                }
                                                
                                                // 在开发环境下抛出异常
                                                throw new \Exception(
                                                    sprintf(
                                                        'Hook 未定义规约：%s。请在模块 %s 的 hook.php 文件中定义此 hook 的规约。',
                                                        $hook_name,
                                                        $moduleName
                                                    )
                                                );
                                            }
                                        }
                                    } catch (\Throwable $e) {
                                        // 如果是我们主动抛出的异常（hook 未定义规约），继续抛出
                                        if (str_contains($e->getMessage(), 'Hook 未定义规约')) {
                                            throw $e;
                                        }
                                        // 如果 HookRegistry 不可用，记录错误但继续执行
                                    }
                                    
                                    // 在开发模式下，获取 hook 实现文件列表并添加注释
                                    try {
                                        // 使用缓存的 HookReader 实例
                                        if ($cachedHookReader === null) {
                                            $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                                        }
                                        $hookReader = $cachedHookReader;
                                        $hookReader->setPath($hook_name);
                                        // 获取原始文件列表（不使用 callback，获取绝对路径）
                                        $hook_files_raw = $hookReader->getFileList(function($modules_files) {
                                            return $modules_files; // 返回原始格式
                                        });
                                        
                                        if (!empty($hook_files_raw)) {
                                            $hook_comment = "<!-- Hook: {$hook_name} -->\n";
                                            $hook_comment .= "<!-- Hook 实现来源（开发模式显示）:\n";
                                            foreach ($hook_files_raw as $module => $file) {
                                                // 提取相对路径（相对于项目根目录）
                                                if (strpos($file, BP) === 0) {
                                                    $relativePath = str_replace(BP, '', $file);
                                                    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                                                } else {
                                                    // 如果已经是相对路径格式（Module::path），直接使用
                                                    $relativePath = str_replace($module . '::', '', $file);
                                                }
                                                
                                                $hook_comment .= "  - 模块: {$module}\n";
                                                $hook_comment .= "    文件: {$relativePath}\n";
                                                
                                                // 检查是否有 hover 相关的 CSS 类
                                                $cssClass = str_replace(['::', '-'], ['-', '-'], $hook_name);
                                                $hook_comment .= "    CSS 类: .{$cssClass}, .header-{$cssClass}\n";
                                            }
                                            $hook_comment .= "  Hover 展开: 检查 CSS 中是否有 .header-{$hook_name}:hover 或相关 hover 样式\n";
                                            $hook_comment .= "-->\n";
                                            
                                            return $hook_comment . self::PHP_OPEN_TAG . '=$this->getHook(\'' . $hook_name . '\')' . self::PHP_CLOSE_TAG;
                                        }
                                    } catch (\Throwable $e) {
                                        // 如果获取文件列表失败，继续执行但不添加注释
                                    }
                                }
                                
                                return self::PHP_OPEN_TAG . '=$this->getHook(\'' . $hook_name . '\')' . self::PHP_CLOSE_TAG;
                            } else {
                                // hook 不存在或没有实现文件，返回 else 内容
                                // 注意：<else/> 在 hook 标签中只用作切分，不需要转换为 PHP else
                                // 返回的 else_content 中不包含用于切分的 <else/> 标签
                                // 如果 else_content 中有其他 <else/>，由 tagReplace 的后续处理来处理
                                return $else_content;
                            }
                        } else {
                            // 单标签情况：支持 @hook() 和 @hook{} 两种格式
                            // 处理方式与其他标签一致（如 @var(), @lang() 等）
                            if ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                                $hook_name = trim($tag_data[1] ?? '');
                                
                                // 对于 @hook() 或 @hook{} 格式，括号/花括号内的内容应该就是 hook 名称
                                // 但是可能包含后续内容（如另一个 @hook() 调用），需要清理
                                
                                // 先检查是否包含后续的 @hook( 或 @hook{ 或其他标签调用
                                // 如果包含，只保留第一个 hook 名称部分（在遇到后续 hook 调用之前截断）
                                $next_hook_pos = strpos($hook_name, '@hook(');
                                if ($next_hook_pos === false) {
                                    $next_hook_pos = strpos($hook_name, '@hook{');
                                }
                                if ($next_hook_pos !== false) {
                                    $hook_name = trim(substr($hook_name, 0, $next_hook_pos));
                                }
                                
                                // 移除可能混入的 PHP 代码标签和 HTML 标签
                                $hook_name = preg_replace('/<\?php\s*else\s*:?\s*\?>/i', '', $hook_name);
                                $hook_name = preg_replace('/<\?=\s*else\s*\?>/i', '', $hook_name);
                                // 移除可能混入的 HTML 标签
                                $hook_name = preg_replace('/<[^>]+>/', '', $hook_name);
                                // 移除可能混入的 PHP 代码
                                $hook_name = preg_replace('/<\?[^?]+\?>/', '', $hook_name);
                                
                                // 只保留 hook 名称（在遇到非合法字符前停止）
                                // hook 名称格式：Module::area::type::component::position，只允许字母、数字、下划线、连字符、冒号
                                // 遇到空格、括号等字符时，应该截断
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                                
                                // 在开发环境下，检查 hook 是否有规约（在 hook.php 中定义）
                                // 统一要求所有 hook 都必须定义规约
                                // 再次清理 hook 名称，确保没有混入其他内容
                                $hook_name = preg_replace('/<[^>]+>/', '', $hook_name); // 移除 HTML 标签
                                $hook_name = preg_replace('/<\?[^?]+\?>/', '', $hook_name); // 移除 PHP 代码
                                // 只保留 hook 名称（在遇到非合法字符前停止，防止包含后续内容）
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                            } else {
                                // 其他格式（向后兼容）
                                $hook_name = trim($tag_data[1] ?? '');
                                $hook_name = preg_replace('/[^a-zA-Z0-9_\-:].*$/', '', $hook_name);
                                $hook_name = trim($hook_name);
                            }
                            
                            if (defined('DEV') && DEV) {
                                try {
                                    $hookRegistry = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Hook\HookRegistry::class);
                                    
                                    // 在开发环境下，如果 generated/hooks.php 不存在，提示需要运行 setup:upgrade
                                    $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'hooks.php';
                                    if (!file_exists($registryFile)) {
                                        throw new \Exception(
                                            sprintf(
                                                'Hook 注册表文件不存在，请先运行 php bin/w setup:upgrade 命令收集注册表信息。'
                                            )
                                        );
                                    }
                                    
                                    if (!$hookRegistry->hasSpec($hook_name)) {
                                        // 解析模块名
                                        $moduleName = '';
                                        if (str_contains($hook_name, '::')) {
                                            // 新格式：ModuleName::area::type::component::position
                                            $parts = explode('::', $hook_name);
                                            $moduleName = $parts[0] ?? '';
                                        } else {
                                            // 简单格式：尝试从注册表中查找所属模块
                                            $hookModule = $hookRegistry->getHookModule($hook_name);
                                            if ($hookModule) {
                                                $moduleName = $hookModule;
                                            } else {
                                                // 如果找不到，提示用户需要定义规约
                                                $moduleName = '相关模块';
                                            }
                                        }
                                        
                                        // 在开发环境下抛出异常
                                        throw new \Exception(
                                            sprintf(
                                                'Hook 未定义规约：%s。请在模块 %s 的 hook.php 文件中定义此 hook 的规约。',
                                                $hook_name,
                                                $moduleName
                                            )
                                        );
                                    }
                                } catch (\Throwable $e) {
                                    // 如果是我们主动抛出的异常（hook 未定义规约），继续抛出
                                    if (str_contains($e->getMessage(), 'Hook 未定义规约')) {
                                        throw $e;
                                    }
                                    // 如果 HookRegistry 不可用，记录错误但继续执行
                                    // 这样可以在运行时处理
                                }
                            }
                        
                        // 在编译阶段尝试检查 hook 是否有实现文件（用于开发模式注释）
                        // 但即使没有找到文件，也生成 getHook() 调用，让运行时处理
                        $hook_files = [];
                        try {
                            // 使用缓存的 HookReader 实例
                            if ($cachedHookReader === null) {
                                $cachedHookReader = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Hook\Config\HookReader::class);
                            }
                            $hookReader = $cachedHookReader;
                            $hookReader->setPath($hook_name);
                            $hook_files = $hookReader->getFileList();
                        } catch (\Throwable $e) {
                            // 如果检查失败（例如 Hook 模块未安装或 HookReader 不可用），继续生成 PHP 代码
                            // 这样可以在运行时处理
                        }
                        
                        // 在开发模式下，如果有实现文件，添加 hook 实现来源注释
                        if (defined('DEV') && DEV && !empty($hook_files)) {
                            $hook_comment = "<!-- Hook: {$hook_name} -->\n";
                            $hook_comment .= "<!-- Hook 实现来源（开发模式显示）:\n";
                            foreach ($hook_files as $module => $file) {
                                // 提取相对路径
                                if (strpos($file, BP) === 0) {
                                    $relativePath = str_replace(BP, '', $file);
                                    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                                } else {
                                    // 如果已经是相对路径格式（Module::path），直接使用
                                    $relativePath = str_replace($module . '::', '', $file);
                                }
                                
                                $hook_comment .= "  - 模块: {$module}\n";
                                $hook_comment .= "    文件: {$relativePath}\n";
                                
                                // 检查是否有 hover 相关的 CSS 类
                                $cssClass = str_replace(['::', '-'], ['-', '-'], $hook_name);
                                $hook_comment .= "    CSS 类: .{$cssClass}, .header-{$cssClass}\n";
                            }
                            $hook_comment .= "  Hover 展开: 检查 CSS 中是否有 .header-{$hook_name}:hover 或相关 hover 样式\n";
                            $hook_comment .= "-->\n";
                            
                            return $hook_comment . self::PHP_OPEN_TAG . '=$this->getHook(\'' . $hook_name . '\')' . self::PHP_CLOSE_TAG;
                        }
                        
                        // 始终生成 PHP 代码，让运行时处理 hook 文件查找
                        // 即使编译时没有找到文件，运行时可能能找到（因为缓存可能已更新）
                        return self::PHP_OPEN_TAG . '=$this->getHook(\'' . $hook_name . '\')' . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            'if' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'attr' => ['condition' => 1],
                'callback' => function ($tag_key, $config, $tag_data, $attributes) {
                    $result = '';
                    switch ($tag_key) {
                        // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $condition = $this->varParser($content_arr[0][0]);
                                $result = self::PHP_OPEN_TAG . 'php if(' . $condition . '):echo ' . $content_arr[0][1] . ';endif;' . self::PHP_CLOSE_TAG;
                            } else {
                                foreach ($content_arr as $key => $data) {
                                    // 统一转成数组，避免在 count() / 下标访问时出现字符串类型
                                    $dataArray = (array)$data;
                                    if (0 === $key) {
                                        $condition = $this->varParser($dataArray[0]);
                                        $result = self::PHP_OPEN_TAG . 'php if(' . $condition . '):echo ' . $dataArray[1] . ';';
                                    } else {
                                        if (count($dataArray) > 1) {
                                            $condition = $this->varParser($dataArray[0]);
                                            $result .= " elseif($condition):echo " . $dataArray[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $dataArray[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;' . self::PHP_CLOSE_TAG;
                                    }
                                }
                            }
                            break;
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("if没有自闭合标签:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                        case 'tag-start':
                            # 排除非if和属性标签的情况
                            if (str_starts_with($tag_data[0], '<if ') || str_starts_with($tag_data[0], '<w:if ')) {
                                if (!isset($attributes['condition'])) {
                                    if (str_starts_with($tag_data[0], '<if ')) {
                                        $template_html = htmlentities($tag_data[0]);
                                        throw new TemplateException(__("if标签缺少condition条件属性:[{$template_html}]，示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                                    }
                                }
                                $condition = $this->varParser($attributes['condition']);
                                $result = self::PHP_OPEN_TAG . 'php if(' . $condition . '):' . self::PHP_CLOSE_TAG;
                                break;
                            }
                            $result = $tag_data[0];
                            break;
                        case 'tag-end':
                            $result = self::PHP_OPEN_TAG . 'php endif;' . self::PHP_CLOSE_TAG;
                            break;
                        default:
                    }
                    return $result;
                }
            ],
            'elseif' => [
                'attr' => ['condition' => 1],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            case 'tag-self-close-with-attrs':
                                $condition = $this->varParser($this->checkVar($attributes['condition']));
                                $result = self::PHP_OPEN_TAG . 'php elseif(' . $condition . '):' . self::PHP_CLOSE_TAG;
                                break;
                            default:
                        }
                        return $result;
                    }],
            'else' => [
                'tag-self-close' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%{1}", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            // <else/> - 支持自闭合和非自闭合形式
                            case 'tag-self-close':
                                // 如果有子内容（标签被错误识别为非自闭合时），也一起输出
                                $content = $tag_data[2] ?? '';
                                $result = self::PHP_OPEN_TAG . 'php else:' . self::PHP_CLOSE_TAG . $content;
                                break;
                            default:
                        }
                        return $result;
                    }],
            'empty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return self::PHP_OPEN_TAG . 'php if(empty(' . $name . '))echo \'' . $template->tmp_replace(trim($content_arr[1] ?? '')) . '\'' . self::PHP_CLOSE_TAG;
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[{$template_html}] 例如：%{1}", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return self::PHP_OPEN_TAG . 'php if(empty(' . $name . ')): ' . self::PHP_CLOSE_TAG;
                        case 'tag-end':
                            return self::PHP_OPEN_TAG . 'php endif; ' . self::PHP_CLOSE_TAG;
                        default:
                            return '';
                    }
                }
            ],
            'notempty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return self::PHP_OPEN_TAG . 'php if(!empty(' . $name . '))echo \'' . $template->tmp_replace(trim($content_arr[1] ?? '')) . '\'' . self::PHP_CLOSE_TAG;
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[$template_html]例如：%{1}", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return self::PHP_OPEN_TAG . 'php if(!empty(' . $name . ') ): ' . self::PHP_CLOSE_TAG;
                        case 'tag-end':
                            return self::PHP_OPEN_TAG . 'php endif; ' . self::PHP_CLOSE_TAG;
                        default:
                            return '';
                    }
                }
            ],
            'has' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $name = $this->varParser($content_arr[0][0]);
                                $result = self::PHP_OPEN_TAG . 'php if(!empty(' . $name . ')):echo ' . $content_arr[0][1] . ';endif;' . self::PHP_CLOSE_TAG;
                            } else {
                                $result = '';
                                foreach ($content_arr as $key => $data) {
                                    // 统一转为数组，避免 count()/下标访问时类型为 string
                                    $dataArray = (array)$data;
                                    if (0 === $key) {
                                        $name = $this->varParser($dataArray[0]);
                                        $result = self::PHP_OPEN_TAG . 'php if(!empty(' . $name . ')):echo ' . $dataArray[1] . ';';
                                    } else {
                                        if (count($dataArray) > 1) {
                                            $name = $this->varParser($dataArray[0]);
                                            $result .= ' elseif(!empty(' . $name . ')):echo ' . $dataArray[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $dataArray[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;' . self::PHP_CLOSE_TAG;
                                    }
                                }
                            }
                            return $result;
                        //                            $content_arr = explode('|', $tag_data[1]);
                        //                            $name        = $this->varParser($this->checkVar($content_arr[0]));
                        /*                            return $phpOpen . 'php if(!empty(' . $name . '))echo \'' . $template->tmp_replace(trim($content_arr[1] ?? '')) . '\'' . $phpClose;*/
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("has标签需要设置name属性:[$template_html]例如：%{1}", htmlentities('<has name="catalogs"><li>有数据</li><else/>没数据</has>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return self::PHP_OPEN_TAG . 'php if(!empty(' . $name . ') ): ' . self::PHP_CLOSE_TAG;
                        case 'tag-end':
                            return self::PHP_OPEN_TAG . 'php endif; ' . self::PHP_CLOSE_TAG;
                        default:
                            return '';
                    }
                }
            ],
            'block' => [
                'doc' => '@block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}或者@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)或者' . htmlentities('<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>') . '或者' . htmlentities('<block>Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml</block>'),
                'tag' => 1,
                'attr' => ['class' => 0, 'template' => 0, 'cache' => 0],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            //<block>Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300</block>
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $data = array_merge($data, $attributes);
                                $result = self::PHP_OPEN_TAG . 'php echo framework_view_process_block(' . w_var_export($data, true) . ');' . self::PHP_CLOSE_TAG;
                                break;
                            // @block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}
                            case '@tag{}':
                            case '@tag()':
                                $data = explode('|', $tag_data[1]);
                                if (!isset($data[0]) || !$data[0]) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(
                                        __(
                                            "@block标签语法使用错误：未指定block类:[{$template_html}]。示例：%{1}或者%{2}",
                                            [htmlentities('@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml)'), htmlentities('@block{Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml}')]
                                        )
                                    );
                                }
                                $result = self::PHP_OPEN_TAG . 'php echo framework_view_process_block(' . w_var_export($data, true) . ');' . self::PHP_CLOSE_TAG;
                                break;
                            // <block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml'/>
                            case 'tag-self-close-with-attrs':
                                if (!isset($attributes['class']) || !$attributes['class']) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(__("block标签语法使用错误:[{$template_html}]：未指定block类。示例：%{1}", htmlentities("<block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml' vars='item|pageSize|page'/>")));
                                }
                                // 变量导入
                                $vars_string = '[';
                                if (isset($attributes['vars'])) {
                                    $vars = explode(',', $attributes['vars']);
                                    foreach ($vars as $key => $var) {
                                        $var_name = trim($var);
                                        $var = '$' . $var_name;
                                        $vars_string .= "'$var_name'=>&$var,";
                                    }
                                }
                                $vars_string .= ']';
                                $result = self::PHP_OPEN_TAG . 'php echo framework_view_process_block(' . w_var_export($attributes, true) . ',$vars=' . $vars_string . ');' . self::PHP_CLOSE_TAG;
                                break;
                            default:
                        }
                        return $result;
                    }
            ],
            'foreach' => [
                'attr' => ['name' => 1, 'key' => 0, 'item' => 0],
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @foreach{$name as $key=>$v|<li><var>$k</var>:<var>$v</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $foreach_str = $this->varParser($this->checkVar($content_arr[0]));
                            return self::PHP_OPEN_TAG . 'php
                        foreach(' . $foreach_str . '){
                        ' . self::PHP_CLOSE_TAG . '
                            ' . $template->tmp_replace($content_arr[1] ?? '') . '
                            ' . self::PHP_OPEN_TAG . 'php
                        }
                        ' . self::PHP_CLOSE_TAG;
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("foreach没有自闭合标签:[{$template_html}]。示例：%{1}", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                        case 'tag-start':
                            if (!isset($attributes['item'])) {
                                $attributes['item'] = 'v';
                            }
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("foreach标签需要指定要循环的变量name属性:[{$template_html}]。例如：需要循环catalogs变量则%{1}", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                            }
                            foreach ($attributes as $key => $attribute) {
                                $attributes[$key] = $this->checkVar($attribute);
                            }
                            $vars = $this->varParser($this->checkVar($attributes['name']));
                            $k_i = isset($attributes['key']) ? $attributes['key'] . ' => ' . $attributes['item'] : $attributes['item'];
                            return self::PHP_OPEN_TAG . 'php foreach(' . $vars . ' as ' . $k_i . '):' . self::PHP_CLOSE_TAG;
                        case 'tag-end':
                            return self::PHP_OPEN_TAG . 'php endforeach;' . self::PHP_CLOSE_TAG;
                        default:
                            return '';
                    }
                }
            ],
            'static' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => $template->fetchTagSource('statics', trim($tag_data[2])),
                            default => $template->fetchTagSource('statics', trim($tag_data[1]))
                        };
                    }
            ],
            'template' => [
                'tag' => 1,
                'attr' => ['enable' => 0],
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $enable = $attributes['enable'] ?? 1;
                        if (!$enable or ($enable === 'false')) {
                            $template_string = $tag_data[0] ?? '';
                            $target_template = $tag_data[2] ?? '';
                            return "<!-- 模块被禁用：{$target_template} 原始模板：{$template_string}-->";
                        }
                        return match ($tag_key) {
                            'tag' => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[2]))),
                            default => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[1])))
                        };
                    }
            ],
            'js' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<script {$tag_data[1]} src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}'></script>",
                            default => "<script src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}'></script>"
                        };
                    }
            ],
            'css' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<link {$tag_data[1]} href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}' rel=\"stylesheet\" type=\"text/css\"/>",
                            default => "<link href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}' rel=\"stylesheet\" type=\"text/css\"/>"
                        };
                    }
            ],
            'lang' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        // 处理 @lang() 和 @lang{} 格式的参数
                        if ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                            $content = trim($tag_data[1] ?? '');
                            
                            // 检查是否包含逗号（可能是参数分隔符）
                            // 注意：需要处理字符串中的逗号，不能简单按逗号分割
                            // 先尝试解析：可能是 "文本, 参数" 或 "文本" 格式
                            $word = $content;
                            $args_code = null;
                            
                            // 尝试解析参数（如果内容以引号开始，可能是字符串参数）
                            // 否则检查是否有逗号分隔的参数
                            if (preg_match('/^([^,]+?)\s*,\s*(.+)$/', $content, $matches)) {
                                // 有逗号，可能是参数格式：@lang(文本, 参数)
                                $word = trim($matches[1]);
                                $args_code = trim($matches[2]);
                                
                                // 移除可能的引号
                                $word = trim($word, '\'"');
                                
                                // 返回带参数的 PHP 代码
                                return self::PHP_OPEN_TAG . '=__(\'' . addslashes($word) . '\', ' . $args_code . ')' . self::PHP_CLOSE_TAG;
                            } else {
                                // 无参数处理
                                $word = trim($content);
                                
                                // 如果是引号包裹的字符串，直接编译期翻译
                                if (preg_match('/^([\'"])(.*)\1$/', $word, $matches)) {
                                    return __($matches[2]);
                                }
                                
                                // 检查是否是 PHP 变量或表达式（以 $ 开头，或包含 -> 或 ::）
                                $isPhpExpression = preg_match('/^\$/', $word) || 
                                                   strpos($word, '->') !== false || 
                                                   strpos($word, '::') !== false ||
                                                   preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $word); // 函数调用
                                
                                if ($isPhpExpression) {
                                    // PHP 表达式，运行期执行
                                    return self::PHP_OPEN_TAG . '=__(' . $content . ')' . self::PHP_CLOSE_TAG;
                                }
                                
                                // 普通文本字符串（可能包含空格、中文等），编译期翻译
                                return __($word);
                            }
                        }
                        
                        // 处理 <lang> 标签格式
                        $word = match ($tag_key) {
                            'tag' => $tag_data[2] ?? '',
                            default => $tag_data[1] ?? ''
                        };
                        $word = trim($word);
                        
                        // 处理 args 属性
                        if (isset($attributes['args']) && !empty($attributes['args'])) {
                            // 如果有 args 属性，传递给 __() 函数
                            $args_code = $attributes['args'];
                            return self::PHP_OPEN_TAG . '=__(\'' . addslashes($word) . '\', ' . $args_code . ')' . self::PHP_CLOSE_TAG;
                        } else {
                            // 没有 args 属性，直接调用 __() 函数
                            return __($word);
                        }
                    }
            ],
            'url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getUrl(\'' . $var . '\',' . $arr_str . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getUrl(\'' . $var . '\')' . self::PHP_CLOSE_TAG;
                                }
                                break;
                            case  'tag-start':
                                $result .= self::PHP_OPEN_TAG . '=$this->getUrl(';
                                break;
                            case 'tag-end':
                                $result .= ')' . self::PHP_CLOSE_TAG;
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= self::PHP_OPEN_TAG . '=$this->getUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                        };
                        return $result;
                    }
            ],
            'frontend-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getFrontendUrl(\'' . $var . '\',' . $arr_str . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getFrontendUrl(\'' . $var . '\')' . self::PHP_CLOSE_TAG;
                                }
                                break;
                            case  'tag-start':
                                $result .= self::PHP_OPEN_TAG . '=$this->getFrontendUrl(';
                                break;
                            case 'tag-end':
                                $result .= ')' . self::PHP_CLOSE_TAG;
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= self::PHP_OPEN_TAG . '=$this->getFrontendUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                        };
                        return $result;
                    }
            ],
            'api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getApi(\'' . $var . '\',' . $arr_str . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    $result .= self::PHP_OPEN_TAG . '=$this->getApi(\'' . $var . '\')' . self::PHP_CLOSE_TAG;
                                }
                                break;
                            case  'tag-start':
                                $result .= self::PHP_OPEN_TAG . '=$this->getApi(';
                                break;
                            case 'tag-end':
                                $result .= ')' . self::PHP_CLOSE_TAG;
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= self::PHP_OPEN_TAG . '=$this->getApi(' . $data . ')' . self::PHP_CLOSE_TAG;
                        };
                        return $result;
                    }
            ],
            'admin-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                            // no break
                            case 'tag-start':
                                return self::PHP_OPEN_TAG . '=$this->getBackendUrl(';
                            case 'tag-end':
                                return ')' . self::PHP_CLOSE_TAG;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                        }
                    }
            ],
            'backend-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                            // no break
                            case 'tag-start':
                                return self::PHP_OPEN_TAG . '=$this->getBackendUrl(';
                            case 'tag-end':
                                return ')' . self::PHP_CLOSE_TAG;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendUrl(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                        }
                    }
            ],
            'backend-api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendApi(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendApi(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                            // no break
                            case 'tag-start':
                                return self::PHP_OPEN_TAG . '=$this->getBackendApi(';
                            case 'tag-end':
                                return ')' . self::PHP_CLOSE_TAG;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendApi(' . $data . ')' . self::PHP_CLOSE_TAG;
                                } else {
                                    return self::PHP_OPEN_TAG . '=$this->getBackendApi(' . $this->varParser($data) . ')' . self::PHP_CLOSE_TAG;
                                }
                        }
                    }
            ],
            'string' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $string = $tag_data[2];
                                $str_arr = explode('|', $string);
                                $str_var = $this->varParser($this->checkVar(array_shift($str_arr)));
                                $str_len = intval(array_shift($str_arr));
                                return self::PHP_OPEN_TAG . 'php if(!empty(' . $str_var . ')&&' . $str_len . '>0 && strlen(' . $str_var . ')>' . $str_len . '){
                                    echo mb_substr(' . $str_var . ',0,' . $str_len . ',\'UTF8\').\'...\';
                                }else{
                                echo ' . $str_var . ';
                                }' . self::PHP_CLOSE_TAG;
                            default:
                                $string = $tag_data[1];
                                $str_arr = explode('|', $string);
                                $str_var = $this->checkVar(array_shift($str_arr));
                                $str_len = intval(array_shift($str_arr));
                                return self::PHP_OPEN_TAG . 'php if(' . $str_len . '>0 && strlen(' . $str_var . ')>' . $str_len . '){
                                    echo mb_substr(' . $str_var . ',0,' . $str_len . ',\'UTF8\').\'...\';
                                }else{
                                echo ' . $str_var . ';
                                }' . self::PHP_CLOSE_TAG;
                        }
                    }
            ],
            'csrf' => [
                'tag' => 1,
                'doc' => '@csrf{demo}或者@csrf(demo)或者' . htmlentities('<csrf name="demo"/>') . '或者' . htmlentities('<csrf>demo</csrf>') . ' 协助在form表单中设置csrf令牌，防止跨站请求伪造（CSRF）攻击',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $name = $tag_data[2] ?? '';
                            // no break
                            case 'tag-self-close-with-attrs':
                                $name = $attributes['name'] ?? '';
                            // no break
                            default:
                                if (empty($name)) {
                                    $name = $tag_data[1] ?? 'csrf';
                                }
                                /**@var Csrf $csrf */
                                $csrf = ObjectManager::getInstance(Csrf::class);
                                return $csrf->render($name);
                        }
                    }
            ],
            'message' => [
                'tag' => 1,
                'doc' => '@message{}或者@message()或者' . htmlentities('<message/>') . '或者' . htmlentities('<message></message>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
            'msg' => [
                'tag' => 1,
                'doc' => '@msg{}或者@msg()或者' . htmlentities('<msg/>') . '或者' . htmlentities('<msg></msg>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
        ];
        # 兼容自定义tag
        /**@var EventsManager $event */
        $event = ObjectManager::getInstance(EventsManager::class);
        $data = (new DataObject(['template' => $template, 'tags' => $tags, 'Taglib' => $this]));
        $event->dispatch('Weline_Framework_Template::after_tags_config', $data);
        $tags = $data->getData('tags') ?: $tags;
        
        # 构造w:tag，确保w:标签紧挨着原始标签且优先处理
        $reordered_tags = [];
        foreach ($tags as $tag => $tag_data) {
            $reordered_tags["w:$tag"] = $tag_data;  // w:标签优先
            $reordered_tags[$tag] = $tag_data;      // 原始标签紧随其后
        }
        $tags = $reordered_tags;
        
        // 缓存结果，避免同一请求内重复收集
        self::$cachedTags = $tags;
        
        return $tags;
    }

    private function removeHtmlComments(string $content): string
    {
        $result = '';
        $len = strlen($content);
        $i = 0;
        while ($i < $len) {
            if ($content[$i] === '<' && substr($content, $i, 4) === '<!--') {
                $end = strpos($content, '-->', $i + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 3;
                continue;
            }
            $result .= $content[$i];
            $i++;
        }
        return $result;
    }

    private function advancePosition(string $text, int &$line, int &$column): void
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
        }
    }

    /**
     * 使用 PHP tokenizer 查找 PHP 块的真正结束位置
     * 正确处理字符串和注释中的 PHP_CLOSE_TAG 序列
     * 
     * @param string $content 完整模板内容
     * @param int $start PHP 块开始位置（指向 '<'）
     * @return int|false PHP_CLOSE_TAG 中第一个字符的位置，或 false 表示未找到
     */
    private function findPhpBlockEnd(string $content, int $start): int|false
    {
        // 从 PHP 块开始位置提取剩余内容进行 tokenize
        $phpContent = substr($content, $start);
        
        // 使用 PHP tokenizer 解析
        $tokens = @token_get_all($phpContent);
        
        $position = 0;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                // [token_id, text, line]
                $tokenText = $token[1];
                $tokenId = $token[0];
                
                // T_CLOSE_TAG 是 PHP 关闭标签
                if ($tokenId === T_CLOSE_TAG) {
                    // 返回 PHP_CLOSE_TAG 中第一个字符的绝对位置
                    return $start + $position;
                }
                
                $position += strlen($tokenText);
            } else {
                // 单字符 token
                $position += strlen($token);
            }
        }
        
        // 没有找到关闭标签
        return false;
    }

    /**
     * @return AstToken[]
     * @throws TemplateException
     */
    private function tokenizeTemplate(string $content): array
    {
        $tokens = [];
        $len = strlen($content);
        $i = 0;
        $line = 1;
        $column = 1;
        $buffer = '';
        $bufferLine = 1;
        $bufferColumn = 1;

        $flushText = function () use (&$buffer, &$tokens, &$bufferLine, &$bufferColumn) {
            if ($buffer !== '') {
                $tokens[] = new AstToken('TEXT', $buffer, $bufferLine, $bufferColumn);
                $buffer = '';
            }
        };

        while ($i < $len) {
            $char = $content[$i];
            $next = $i + 1 < $len ? $content[$i + 1] : '';

            if ($char === '<' && $next === '?') {
                $flushText();
                $startLine = $line;
                $startColumn = $column;
                $end = $this->findPhpBlockEnd($content, $i);
                if ($end === false) {
                    // 没有找到闭合，整个剩余内容作为 PHP 块
                    $phpBlock = substr($content, $i);
                    $tokens[] = new AstToken('PHP_BLOCK', $phpBlock, $startLine, $startColumn);
                    $this->advancePosition($phpBlock, $line, $column);
                    break;
                }
                $phpBlock = substr($content, $i, $end - $i + 2);
                $tokens[] = new AstToken('PHP_BLOCK', $phpBlock, $startLine, $startColumn);
                $this->advancePosition($phpBlock, $line, $column);
                $i = $end + 2;
                continue;
            }

            if ($char === '@') {
                $tagToken = $this->tryParseInlineTagToken($content, $i, $line, $column);
                if ($tagToken instanceof AstToken) {
                    $flushText();
                    $tokens[] = $tagToken;
                    $raw = $tagToken->value;
                    $this->advancePosition($raw, $line, $column);
                    $i += strlen($raw);
                    continue;
                }
            }

            if ($char === '<' && $next !== '?') {
                if (substr($content, $i, 4) === '<!--') {
                    $flushText();
                    $startLine = $line;
                    $startColumn = $column;
                    $end = strpos($content, '-->', $i + 4);
                    if ($end === false) {
                        $comment = substr($content, $i);
                        $tokens[] = new AstToken('TEXT', $comment, $startLine, $startColumn);
                        $this->advancePosition($comment, $line, $column);
                        break;
                    }
                    $comment = substr($content, $i, $end - $i + 3);
                    $tokens[] = new AstToken('TEXT', $comment, $startLine, $startColumn);
                    $this->advancePosition($comment, $line, $column);
                    $i = $end + 3;
                    continue;
                }

                $flushText();
                $startLine = $line;
                $startColumn = $column;
                if ($next === '/') {
                    $nameStart = $i + 2;
                    $nameEnd = $nameStart;
                    while ($nameEnd < $len && preg_match('/[A-Za-z0-9:_-]/', $content[$nameEnd])) {
                        $nameEnd++;
                    }
                    $tagName = substr($content, $nameStart, $nameEnd - $nameStart);
                    
                    // 验证这是一个有效的闭合标签：标签名后应直接跟 > 或只有空白后跟 >
                    // 如果标签名后面是其他字符（如 , 在 JavaScript 正则中 /</g, ），则不是有效闭合标签
                    $afterName = $nameEnd < $len ? $content[$nameEnd] : '';
                    $isValidClose = false;
                    if ($afterName === '>') {
                        $isValidClose = true;
                    } elseif (ctype_space($afterName)) {
                        // 跳过空白找 >
                        $checkPos = $nameEnd;
                        while ($checkPos < $len && ctype_space($content[$checkPos])) {
                            $checkPos++;
                        }
                        if ($checkPos < $len && $content[$checkPos] === '>') {
                            $isValidClose = true;
                        }
                    }
                    
                    if (!$isValidClose || $tagName === '') {
                        // 不是有效的闭合标签，把 < 当作普通文本
                        $bufferLine = $line;
                        $bufferColumn = $column;
                        $buffer .= $char;
                        $this->advancePosition($char, $line, $column);
                        $i++;
                        continue;
                    }
                    
                    $end = strpos($content, '>', $nameEnd);
                    if ($end === false) {
                        throw new TemplateException(__('Tag close not found.'));
                    }
                    $raw = substr($content, $i, $end - $i + 1);
                    $tokens[] = new AstToken('TAG_CLOSE', $tagName, $startLine, $startColumn, ['raw' => $raw]);
                    $this->advancePosition($raw, $line, $column);
                    $i = $end + 1;
                    continue;
                }

                $nameStart = $i + 1;
                $nameEnd = $nameStart;
                while ($nameEnd < $len && preg_match('/[A-Za-z0-9:_-]/', $content[$nameEnd])) {
                    $nameEnd++;
                }
                $tagName = substr($content, $nameStart, $nameEnd - $nameStart);
                if ($tagName === '') {
                    $bufferLine = $line;
                    $bufferColumn = $column;
                    $buffer .= $char;
                    $this->advancePosition($char, $line, $column);
                    $i++;
                    continue;
                }

                $attrStart = $nameEnd;
                $inQuote = false;
                $quoteChar = '';
                $inPhp = false;
                $pos = $attrStart;
                $selfClosing = false;
                $voidTag = in_array(strtolower($tagName), self::HTML_VOID_TAGS, true);
                // JavaScript 模板字符串 ${...} 跟踪
                $jsTemplateBraceDepth = 0;
                while ($pos < $len) {
                    $c = $content[$pos];
                    $n = $pos + 1 < $len ? $content[$pos + 1] : '';
                    
                    if (!$inQuote && !$inPhp && $c === '/' && $n === '>') {
                        $selfClosing = true;
                        break;
                    }
                    if (!$inQuote && !$inPhp && $c === '>') {
                        break;
                    }
                    if (!$inQuote && $c === '<' && $n === '?') {
                        $inPhp = true;
                        $pos += 2;
                        continue;
                    }
                    if ($inPhp && $c === '?' && $n === '>') {
                        $inPhp = false;
                        $pos += 2;
                        continue;
                    }
                    
                    // 检测 JavaScript 模板字符串 ${...}
                    if ($inQuote && !$inPhp && $c === '$' && $n === '{') {
                        $jsTemplateBraceDepth++;
                        $pos += 2;
                        continue;
                    }
                    // 在 ${...} 内部跟踪嵌套大括号
                    if ($jsTemplateBraceDepth > 0) {
                        if ($c === '{') {
                            $jsTemplateBraceDepth++;
                        } elseif ($c === '}') {
                            $jsTemplateBraceDepth--;
                        }
                        // 在 ${...} 内部，跳过所有其他处理
                        $pos++;
                        continue;
                    }
                    
                    if (!$inPhp && ($c === '"' || $c === "'")) {
                        if (!$inQuote) {
                            $inQuote = true;
                            $quoteChar = $c;
                        } elseif ($quoteChar === $c) {
                            $inQuote = false;
                            $quoteChar = '';
                        }
                    }
                    $pos++;
                }
                if ($pos >= $len) {
                    // 提取标签周围的内容用于调试
                    $contextStart = max(0, $i - 20);
                    $contextEnd = min($len, $i + 100);
                    $context = substr($content, $contextStart, $contextEnd - $contextStart);
                    $context = str_replace(["\r\n", "\r", "\n"], ' ', $context); // 替换换行符
                    $context = htmlentities($context);
                    throw new TemplateException(__('标签未闭合（缺少 ">"）。标签名: <%{1}>，起始行: %{2}，起始列: %{3}。上下文: ...%{4}...', $tagName, $startLine, $startColumn, $context));
                }
                $attrEnd = $pos;
                $rawAttributes = substr($content, $attrStart, $attrEnd - $attrStart);
                if ($voidTag && !$selfClosing) {
                    $selfClosing = true;
                }
                $end = $selfClosing && $content[$pos] === '/' ? $pos + 1 : $pos;
                $raw = substr($content, $i, $end - $i + 1);
                $tokens[] = new AstToken('TAG_OPEN', $tagName, $startLine, $startColumn, [
                    'raw' => $raw,
                    'rawAttributes' => $rawAttributes,
                    'selfClosing' => $selfClosing,
                ]);
                if ($selfClosing) {
                    $tokens[] = new AstToken('TAG_SELF_CLOSE', '/>', $startLine, $startColumn);
                }
                $this->advancePosition(substr($content, $i, $end - $i + 1), $line, $column);
                $i = $end + 1;
                
                // 对于 <script> 和 <style> 标签，跳过其内容直到找到闭合标签
                // 这些标签内的内容不应该被当作 HTML 解析
                if (!$selfClosing && in_array(strtolower($tagName), ['script', 'style'], true)) {
                    $closeTag = '</' . strtolower($tagName);
                    $closeTagPos = stripos($content, $closeTag, $i);
                    if ($closeTagPos !== false) {
                        // 将 script/style 内容作为文本节点
                        $scriptContent = substr($content, $i, $closeTagPos - $i);
                        if ($scriptContent !== '') {
                            $tokens[] = new AstToken('TEXT', $scriptContent, $line, $column);
                            $this->advancePosition($scriptContent, $line, $column);
                        }
                        $i = $closeTagPos;
                    }
                }
                continue;
            }

            if ($buffer === '') {
                $bufferLine = $line;
                $bufferColumn = $column;
            }
            $buffer .= $char;
            $this->advancePosition($char, $line, $column);
            $i++;
        }
        $flushText();
        $tokens[] = new AstToken('EOF', '', $line, $column);
        return $tokens;
    }

    private function tryParseInlineTagToken(string $content, int $offset, int $line, int $column): ?AstToken
    {
        $len = strlen($content);
        $pos = $offset + 1;
        $name = '';
        while ($pos < $len && preg_match('/[A-Za-z0-9:_-]/', $content[$pos])) {
            $name .= $content[$pos];
            $pos++;
        }
        if ($name === '') {
            return null;
        }
        $brace = $pos < $len ? $content[$pos] : '';
        if ($brace !== '(' && $brace !== '{') {
            return null;
        }
        $close = $brace === '(' ? ')' : '}';
        $pos++;
        $depth = 1;
        $valueStart = $pos;
        $inString = false;
        $stringChar = '';
        while ($pos < $len) {
            $char = $content[$pos];
            // 处理字符串内的内容（不计算括号深度）
            if ($inString) {
                if ($char === $stringChar && ($pos === 0 || $content[$pos - 1] !== '\\')) {
                    $inString = false;
                }
                $pos++;
                continue;
            }
            // 检测字符串开始
            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                $pos++;
                continue;
            }
            // 处理嵌套括号
            if ($char === $brace) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $pos++;
        }
        if ($pos >= $len) {
            return null;
        }
        $value = substr($content, $valueStart, $pos - $valueStart);
        $raw = substr($content, $offset, $pos - $offset + 1);
        return new AstToken('EXPR', $raw, $line, $column, [
            'name' => $name,
            'inlineKey' => $brace === '(' ? '@tag()' : '@tag{}',
            'value' => $value,
            'raw' => $raw,
        ]);
    }

    private function parseTextNodes(string $text, int $line): array
    {
        $nodes = [];
        $len = strlen($text);
        $i = 0;
        $buffer = '';
        while ($i < $len) {
            if ($text[$i] === '{' && $i + 1 < $len && $text[$i + 1] === '{') {
                if ($buffer !== '') {
                    $nodes[] = new TextNode($line, $buffer);
                    $buffer = '';
                }
                $end = strpos($text, '}}', $i + 2);
                if ($end === false) {
                    $buffer .= '{{';
                    $i += 2;
                    continue;
                }
                $varPath = substr($text, $i + 2, $end - $i - 2);
                $parsedValue = $this->varParser(trim($varPath));
                $raw = self::PHP_ECHO_TAG . $parsedValue . ';' . self::PHP_CLOSE_TAG;
                $nodes[] = new PhpNode($line, $raw, trim($parsedValue));
                $i = $end + 2;
                continue;
            }
            if ($text[$i] === '@') {
                $tagToken = $this->tryParseInlineTagToken($text, $i, $line, 1);
                if ($tagToken instanceof AstToken) {
                    if ($buffer !== '') {
                        $nodes[] = new TextNode($line, $buffer);
                        $buffer = '';
                    }
                    $tag = new TagNode($line, $tagToken->meta['name'] ?? '', []);
                    $tag->tagKey = $tagToken->meta['inlineKey'] ?? '@tag()';
                    $tag->inlineContent = $tagToken->meta['value'] ?? '';
                    $tag->raw = $tagToken->meta['raw'] ?? $tagToken->value;
                    $nodes[] = $tag;
                    $i += strlen($tagToken->value);
                    continue;
                }
            }
            $buffer .= $text[$i];
            $i++;
        }
        if ($buffer !== '') {
            $nodes[] = new TextNode($line, $buffer);
        }
        return $nodes;
    }

    /**
     * 解析文本中的内联标签（@tag() 和 @tag{} 格式）
     * 用于处理非框架 HTML 标签中可能包含的内联标签
     */
    private function parseTextWithInlineTags(string $text, int $line): array
    {
        $nodes = [];
        $len = strlen($text);
        $i = 0;
        $buffer = '';
        
        while ($i < $len) {
            if ($text[$i] === '@') {
                $tagToken = $this->tryParseInlineTagToken($text, $i, $line, 1);
                if ($tagToken instanceof AstToken) {
                    if ($buffer !== '') {
                        // 递归处理 buffer 中可能的 {{}} 变量
                        $nodes = array_merge($nodes, $this->parseTextNodes($buffer, $line));
                        $buffer = '';
                    }
                    // 创建内联标签节点
                    $tag = new TagNode($line, $tagToken->meta['name'] ?? '', []);
                    $tag->tagKey = $tagToken->meta['inlineKey'] ?? '@tag()';
                    $tag->inlineContent = $tagToken->meta['value'] ?? '';
                    $tag->raw = $tagToken->meta['raw'] ?? $tagToken->value;
                    $nodes[] = $tag;
                    $i += strlen($tagToken->value);
                    continue;
                }
            }
            $buffer .= $text[$i];
            $i++;
        }
        
        if ($buffer !== '') {
            // 递归处理 buffer 中可能的 {{}} 变量
            $nodes = array_merge($nodes, $this->parseTextNodes($buffer, $line));
        }
        
        return $nodes;
    }
    
    /**
     * 解析非框架标签的完整标记，处理其属性值中可能包含的框架标签
     * 例如：<input placeholder="<lang>搜索商品...</lang>">
     */
    private function parseNonFrameworkTagMarkup(string $markup, int $line, array $frameworkTags): array
    {
        // 解析标签的属性
        if (!preg_match('/^<([A-Za-z0-9:_-]+)(.*)$/s', $markup, $tagMatch)) {
            // 不是有效的标签格式，直接返回文本节点
            return [new TextNode($line, $markup)];
        }
        
        $tagName = $tagMatch[1];
        $rest = $tagMatch[2];
        
        // 检查是否自闭合
        $selfClosing = false;
        if (preg_match('/\/>$/', $rest)) {
            $selfClosing = true;
            $attrPart = preg_replace('/\s*\/>$/', '', $rest);
        } elseif (preg_match('/>$/', $rest)) {
            $attrPart = preg_replace('/\s*>$/', '', $rest);
        } else {
            // 格式不对，直接返回
            return [new TextNode($line, $markup)];
        }
        
        // 解析属性
        $attrPart = trim($attrPart);
        if ($attrPart === '') {
            // 没有属性，直接返回原标记
            return $this->parseTextWithInlineTags($markup, $line);
        }
        
        // 使用属性解析器解析属性
        $attrMap = $this->parseAttributesWithTokenizer($attrPart);
        
        // 获取 data-skip-parse 属性，确定哪些属性不需要解析
        $skipParseAttrs = [];
        if (isset($attrMap['data-skip-parse'])) {
            $skipParseAttrs = array_map('trim', explode(',', $attrMap['data-skip-parse']));
        }
        
        // 检查是否有属性值包含框架标签或内联标签
        $hasFrameworkTagInAttr = false;
        $hasInlineTagInAttr = false;
        foreach ($attrMap as $attrName => $attrValue) {
            // 如果属性在跳过列表中，不检查其内容
            if (in_array($attrName, $skipParseAttrs)) {
                continue;
            }
            // 检查是否包含框架标签（<tag> 格式）
            if (strpos($attrValue, '<') !== false && strpos($attrValue, '>') !== false) {
                foreach ($frameworkTags as $ftName => $ftConfig) {
                    if (strpos($attrValue, '<' . $ftName) !== false || strpos($attrValue, '<' . $ftName . '>') !== false) {
                        $hasFrameworkTagInAttr = true;
                        break 2;
                    }
                }
            }
            // 检查是否包含内联标签（@tag() 或 @tag{} 格式）
            if (strpos($attrValue, '@') !== false) {
                // 尝试解析内联标签以确认
                $testToken = $this->tryParseInlineTagToken($attrValue, strpos($attrValue, '@'), $line, 1);
                if ($testToken instanceof AstToken) {
                    $hasInlineTagInAttr = true;
                    break;
                }
            }
        }
        
        if (!$hasFrameworkTagInAttr && !$hasInlineTagInAttr) {
            // 没有框架标签或内联标签在属性值中，使用原来的处理方式
            return $this->parseTextWithInlineTags($markup, $line);
        }
        
        // 有框架标签或内联标签在属性值中，需要特殊处理
        // 构建新的标记，编译属性值中的标签
        $result = [];
        $result[] = new TextNode($line, '<' . $tagName);
        
        foreach ($attrMap as $attrName => $attrValue) {
            // 跳过 data-skip-parse 属性本身，不输出到结果中
            if ($attrName === 'data-skip-parse') {
                continue;
            }
            
            // 如果属性在跳过列表中，直接输出原始值，不进行解析
            if (in_array($attrName, $skipParseAttrs)) {
                $result[] = new TextNode($line, ' ' . $attrName . '="' . $attrValue . '"');
                continue;
            }
            
            // 检查属性值是否包含框架标签或内联标签
            $hasTag = false;
            $hasInlineTag = false;
            
            // 检查框架标签
            foreach ($frameworkTags as $ftName => $ftConfig) {
                if (strpos($attrValue, '<' . $ftName) !== false) {
                    $hasTag = true;
                    break;
                }
            }
            
            // 检查内联标签
            if (strpos($attrValue, '@') !== false) {
                $testToken = $this->tryParseInlineTagToken($attrValue, strpos($attrValue, '@'), $line, 1);
                if ($testToken instanceof AstToken) {
                    $hasInlineTag = true;
                }
            }
            
            if ($hasTag || $hasInlineTag) {
                // 属性值包含框架标签或内联标签，解析并编译
                $attrNodes = $this->parseAttributeValueNodes($attrValue, $line, $frameworkTags);
                $result[] = new TextNode($line, ' ' . $attrName . '="');
                $result = array_merge($result, $attrNodes);
                $result[] = new TextNode($line, '"');
            } else {
                // 普通属性值
                $result[] = new TextNode($line, ' ' . $attrName . '="' . $attrValue . '"');
            }
        }
        
        $result[] = new TextNode($line, $selfClosing ? '/>' : '>');
        
        return $result;
    }

    private function parseAttributeValueNodes(string $value, int $line, array $frameworkTags = []): array
    {
        $nodes = [];
        $segments = $this->splitPhpBlocks($value);
        foreach ($segments as $segment) {
            if ($segment['type'] === 'php') {
                $parsed = self::parsePhpCodeToken($segment['value']);
                $nodes[] = new PhpNode($line, $segment['value'], $parsed['expression']);
            } else {
                // 检查是否包含标签（<tag>...</tag> 格式）
                $text = $segment['value'];
                if (!empty($frameworkTags) && strpos($text, '<') !== false && strpos($text, '>') !== false) {
                    // 尝试解析属性值中的标签
                    $nodes = array_merge($nodes, $this->parseAttributeValueWithTags($text, $line, $frameworkTags));
                } else {
                    $nodes = array_merge($nodes, $this->parseTextNodes($text, $line));
                }
            }
        }
        return $nodes;
    }
    
    /**
     * 解析属性值中的标签
     * 例如：placeholder="<lang>搜索商品...</lang>"
     */
    private function parseAttributeValueWithTags(string $text, int $line, array $frameworkTags): array
    {
        $nodes = [];
        $len = strlen($text);
        $i = 0;
        $buffer = '';
        
        while ($i < $len) {
            // 检测标签开始
            if ($text[$i] === '<' && $i + 1 < $len && $text[$i + 1] !== '?' && $text[$i + 1] !== '!') {
                // 可能是标签，尝试解析
                $tagMatch = $this->tryParseTagInAttributeValue($text, $i, $line, $frameworkTags);
                if ($tagMatch !== null) {
                    // 先保存之前的文本
                    if ($buffer !== '') {
                        $nodes = array_merge($nodes, $this->parseTextNodes($buffer, $line));
                        $buffer = '';
                    }
                    // 添加解析到的标签节点
                    $nodes[] = $tagMatch['node'];
                    $i = $tagMatch['endPos'];
                    continue;
                }
            }
            
            // 检测 {{variable}} 变量
            if ($text[$i] === '{' && $i + 1 < $len && $text[$i + 1] === '{') {
                if ($buffer !== '') {
                    $nodes[] = new TextNode($line, $buffer);
                    $buffer = '';
                }
                $end = strpos($text, '}}', $i + 2);
                if ($end === false) {
                    $buffer .= '{{';
                    $i += 2;
                    continue;
                }
                $varPath = substr($text, $i + 2, $end - $i - 2);
                $parsedValue = $this->varParser(trim($varPath));
                $raw = self::PHP_ECHO_TAG . $parsedValue . ';' . self::PHP_CLOSE_TAG;
                $nodes[] = new PhpNode($line, $raw, trim($parsedValue));
                $i = $end + 2;
                continue;
            }
            
            // 检测 @static() 等内联标签
            if ($text[$i] === '@') {
                $tagToken = $this->tryParseInlineTagToken($text, $i, $line, 1);
                if ($tagToken instanceof AstToken) {
                    // 先保存之前的文本
                    if ($buffer !== '') {
                        $nodes = array_merge($nodes, $this->parseTextNodes($buffer, $line));
                        $buffer = '';
                    }
                    // 创建内联标签节点
                    $tag = new TagNode($line, $tagToken->meta['name'] ?? '', []);
                    $tag->tagKey = $tagToken->meta['inlineKey'] ?? '@tag()';
                    $tag->inlineContent = $tagToken->meta['value'] ?? '';
                    $tag->raw = $tagToken->meta['raw'] ?? $tagToken->value;
                    $nodes[] = $tag;
                    $i += strlen($tagToken->value);
                    continue;
                }
            }
            
            $buffer .= $text[$i];
            $i++;
        }
        
        if ($buffer !== '') {
            $nodes[] = new TextNode($line, $buffer);
        }
        
        return $nodes;
    }
    
    /**
     * 尝试解析属性值中的标签
     */
    private function tryParseTagInAttributeValue(string $text, int $offset, int $line, array $frameworkTags): ?array
    {
        $len = strlen($text);
        $pos = $offset + 1;
        
        // 检查是否是闭合标签
        $isCloseTag = ($pos < $len && $text[$pos] === '/');
        if ($isCloseTag) {
            return null; // 闭合标签不单独处理
        }
        
        // 读取标签名
        $nameStart = $pos;
        while ($pos < $len && preg_match('/[A-Za-z0-9:_-]/', $text[$pos])) {
            $pos++;
        }
        $tagName = substr($text, $nameStart, $pos - $nameStart);
        
        if ($tagName === '' || !isset($frameworkTags[$tagName])) {
            return null; // 不是框架标签
        }
        
        // 跳过空白
        while ($pos < $len && ctype_space($text[$pos])) {
            $pos++;
        }
        
        // 读取属性（如果有）
        $attrStart = $pos;
        $selfClosing = false;
        while ($pos < $len) {
            if ($text[$pos] === '/' && $pos + 1 < $len && $text[$pos + 1] === '>') {
                $selfClosing = true;
                $pos += 2;
                break;
            }
            if ($text[$pos] === '>') {
                $pos++;
                break;
            }
            $pos++;
        }
        
        $rawAttributes = trim(substr($text, $attrStart, $pos - $attrStart - ($selfClosing ? 2 : 1)));
        
        if ($selfClosing) {
            // 自闭合标签
            $tag = new TagNode($line, $tagName, []);
            $tag->selfClosing = true;
            $tag->rawAttributes = $rawAttributes;
            return ['node' => $tag, 'endPos' => $pos];
        }
        
        // 非自闭合标签，需要找到闭合标签
        $closeTag = '</' . $tagName . '>';
        $closePos = strpos($text, $closeTag, $pos);
        if ($closePos === false) {
            return null; // 找不到闭合标签
        }
        
        $content = substr($text, $pos, $closePos - $pos);
        $endPos = $closePos + strlen($closeTag);
        
        // 创建标签节点
        $tag = new TagNode($line, $tagName, []);
        $tag->selfClosing = false;
        $tag->rawAttributes = $rawAttributes;
        // 递归解析内容中的标签
        $tag->children = $this->parseAttributeValueWithTags($content, $line, $frameworkTags);
        
        return ['node' => $tag, 'endPos' => $endPos];
    }

    private function splitPhpBlocks(string $text): array
    {
        $result = [];
        $len = strlen($text);
        $i = 0;
        $buffer = '';
        while ($i < $len) {
            if ($text[$i] === '<' && $i + 1 < $len && $text[$i + 1] === '?') {
                if ($buffer !== '') {
                    $result[] = ['type' => 'text', 'value' => $buffer];
                    $buffer = '';
                }
                $end = strpos($text, self::PHP_CLOSE_TAG, $i + 2);
                if ($end === false) {
                    $buffer .= substr($text, $i);
                    break;
                }
                $php = substr($text, $i, $end - $i + 2);
                $result[] = ['type' => 'php', 'value' => $php];
                $i = $end + 2;
                continue;
            }
            $buffer .= $text[$i];
            $i++;
        }
        if ($buffer !== '') {
            $result[] = ['type' => 'text', 'value' => $buffer];
        }
        return $result;
    }

    /**
     * @param AstToken[] $tokens
     * @param array $frameworkTags 框架标签名称列表，用于区分框架标签和 HTML 标签
     * @return ProgramNode
     */
    private function parseTokensToAst(array $tokens, array $frameworkTags = []): ProgramNode
    {
        $root = new ProgramNode(1);
        $stack = [$root];
        
        foreach ($tokens as $token) {
            $current = $stack[count($stack) - 1];
            switch ($token->type) {
                case 'TEXT':
                    foreach ($this->parseTextNodes($token->value, $token->line) as $node) {
                        $current->children[] = $node;
                    }
                    break;
                case 'PHP_BLOCK':
                    $current->children[] = new PhpNode($token->line, $token->value, null);
                    break;
                case 'EXPR':
                    $tag = new TagNode($token->line, $token->meta['name'] ?? '', []);
                    $tag->tagKey = $token->meta['inlineKey'] ?? '@tag()';
                    $tag->inlineContent = $token->meta['value'] ?? '';
                    $tag->raw = $token->meta['raw'] ?? $token->value;
                    $current->children[] = $tag;
                    break;
                case 'TAG_OPEN':
                    $tagName = $token->value;
                    $rawAttributes = $token->meta['rawAttributes'] ?? '';
                    $rawMarkup = $token->meta['raw'] ?? '';
                    $selfClosing = (bool)($token->meta['selfClosing'] ?? false);
                    
                    // 判断是否为框架标签（需要严格嵌套）
                    $isFrameworkTag = isset($frameworkTags[$tagName]) || isset($frameworkTags['w:' . $tagName]);
                    
                    if (!$isFrameworkTag) {
                        // 非框架标签：解析其中可能存在的内联标签（如 @static()）和属性值中的框架标签
                        $parsedNodes = $this->parseNonFrameworkTagMarkup($rawMarkup, $token->line, $frameworkTags);
                        foreach ($parsedNodes as $pnode) {
                            $current->children[] = $pnode;
                        }
                        break;
                    }
                    
                    // 框架标签：创建 TagNode
                    $attrMap = $this->parseAttributesWithTokenizer($rawAttributes);
                    $attrNodes = [];
                    foreach ($attrMap as $name => $value) {
                        $attrNodes[] = new AttrNode($token->line, $name, $this->parseAttributeValueNodes($value, $token->line, $frameworkTags));
                    }
                    $tag = new TagNode($token->line, $tagName, $attrNodes);
                    
                    // 获取标签配置
                    $tagConfig = $frameworkTags[$tagName] ?? $frameworkTags['w:' . $tagName] ?? [];
                    
                    // 对于只定义了 tag-self-close 的标签（如 <else/>），即使 token 中没有正确识别为自闭合，
                    // 也应该强制将其视为自闭合标签
                    $isSelfCloseOnlyTag = !empty($tagConfig['tag-self-close']) 
                        && empty($tagConfig['tag']) 
                        && empty($tagConfig['tag-start']) 
                        && empty($tagConfig['tag-end']);
                    
                    $tag->selfClosing = $selfClosing || $isSelfCloseOnlyTag;
                    $tag->rawAttributes = $rawAttributes;
                    $tag->raw = $rawMarkup;
                    
                    if ($tag->selfClosing) {
                        $current->children[] = $tag;
                    } else {
                        $stack[] = $tag;
                    }
                    break;
                case 'TAG_CLOSE':
                    $name = $token->value;
                    $isFrameworkTag = isset($frameworkTags[$name]) || isset($frameworkTags['w:' . $name]);
                    
                    if (!$isFrameworkTag) {
                        // 非框架标签的闭合标签，直接作为文本输出（无需解析内联标签）
                        $current->children[] = new TextNode($token->line, '</' . $name . '>');
                        break;
                    }
                    
                    // 框架标签：在栈中查找匹配的开标签
                    $foundIndex = -1;
                    for ($i = count($stack) - 1; $i >= 1; $i--) {
                        if ($stack[$i] instanceof TagNode && $stack[$i]->name === $name) {
                            $foundIndex = $i;
                            break;
                        }
                    }
                    
                    if ($foundIndex === -1) {
                        // 未找到匹配的开标签，作为文本输出
                        $current->children[] = new TextNode($token->line, '</' . $name . '>');
                        break;
                    }
                    
                    // 自动闭合中间的所有标签
                    while (count($stack) > $foundIndex) {
                        $closed = array_pop($stack);
                        if ($closed instanceof TagNode) {
                            $parent = $stack[count($stack) - 1];
                            $parent->children[] = $closed;
                        }
                    }
                    break;
                case 'EOF':
                    break;
                default:
                    break;
            }
        }
        
        // 自动闭合所有未闭合的标签（宽松模式）
        while (count($stack) > 1) {
            $unclosed = array_pop($stack);
            if ($unclosed instanceof TagNode) {
                $parent = $stack[count($stack) - 1];
                $parent->children[] = $unclosed;
            }
        }
        
        return $root;
    }

    private function buildAttributesFromRaw(string $rawAttributes): array
    {
        $formatedAttributes = $this->parseAttributesWithTokenizer($rawAttributes);
        foreach ($formatedAttributes as $key => $value) {
            if ($value === '') {
                continue;
            }
            $nodes = $this->parseAttributeValueNodes($value, 0);
            $stringValue = $this->buildStringFromNodes($nodes);
            $formatedAttributes[$key] = $stringValue;
        }

        foreach ($formatedAttributes as $key => $value) {
            if ($value === '') {
                continue;
            }
            $parsed = self::parsePhpCodeToken($value);
            if ($parsed['is_php']) {
                $formatedAttributes[$key] = $parsed['expression'];
                $formatedAttributes['__php_' . $key] = $value;
            }
        }
        return $formatedAttributes;
    }

    private function buildStringFromNodes(array $nodes): string
    {
        $result = '';
        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $result .= $node->value;
            } elseif ($node instanceof PhpNode) {
                $result .= $node->code;
            } elseif ($node instanceof TagNode) {
                $result .= $this->buildOriginalTagMarkup($node);
            }
        }
        return $result;
    }

    private function isNodesDynamic(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof PhpNode) {
                return true;
            }
            if ($node instanceof TagNode) {
                return true;
            }
        }
        return false;
    }

    private function isAttributesDynamic(array $attributes): bool
    {
        foreach ($attributes as $attr) {
            if (!$attr instanceof AttrNode) {
                continue;
            }
            if ($this->isNodesDynamic($attr->value)) {
                return true;
            }
        }
        return false;
    }

    private function isInlineContentDynamic(?string $content): bool
    {
        if ($content === null) {
            return false;
        }
        $trimmed = trim($content);
        if ($trimmed === '') {
            return false;
        }
        if (str_contains($trimmed, '<' . '?') || str_contains($trimmed, '{{')) {
            return true;
        }
        if (preg_match('/^([\'"])(.*)\1$/', $trimmed)) {
            return false;
        }
        // 静态路径字符：字母、数字、下划线、点、冒号、短横线、斜杠
        if (preg_match('/^[A-Za-z0-9_.:\-\/]+$/', $trimmed)) {
            return false;
        }
        return true;
    }

    /**
     * 控制流标签 - 这些标签不应该被作为独立框架标签处理，
     * 而是输出为原始 HTML 标记，由父标签（如 hook, if）在其内容中解析处理
     * 
     * 注意：else 和 elseif 需要保持原样，因为：
     * 1. 在 <w:hook> 标签中，<else/> 用作分隔符，由 hook 回调函数解析
     * 2. 在 <if> 标签中，内容会被 tmp_replace 重新处理，<else/> 会被正确编译
     */
    private const PASSTHROUGH_TAGS = ['else', 'elseif', 'w:else', 'w:elseif'];

    private function determineTagStage(TagNode $node): string
    {
        $compileCandidates = [
            'css', 'js', 'url', 'frontend-url', 'backend-url', 'backend-api', 'api',
            'template', 'include', 'static', 'theme:css', 'theme:js', 'theme:template'
        ];
        
        // 强制运行时执行的标签（避免编译时递归）
        $runtimeOnlyTags = ['acl'];
        if (in_array($node->name, $runtimeOnlyTags, true)) {
            return self::STAGE_RUNTIME;
        }
        
        $hasDynamicAttrs = $this->isAttributesDynamic($node->attributes);
        $hasDynamicChildren = $this->isNodesDynamic($node->children);
        $hasDynamicInline = ($node->tagKey === '@tag()' || $node->tagKey === '@tag{}')
            ? $this->isInlineContentDynamic($node->inlineContent)
            : false;
        $isDynamic = $hasDynamicAttrs || $hasDynamicChildren || $hasDynamicInline;

        if ($node->name === 'lang') {
            return $isDynamic ? self::STAGE_RUNTIME : self::STAGE_COMPILE_TIME;
        }

        if (in_array($node->name, $compileCandidates, true)) {
            return $isDynamic ? self::STAGE_RUNTIME : self::STAGE_COMPILE_TIME;
        }

        return self::STAGE_COMPILE_TIME;
    }

    private function compileAst(ProgramNode $root, array $tags, Template $template, string $fileName): ProgramNode
    {
        $root->children = $this->compileNodes($root->children, $tags, $template, $fileName);
        return $root;
    }

    private function compileNodes(array $nodes, array $tags, Template $template, string $fileName): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof TagNode) {
                // 控制流标签（else, elseif）直接输出为原始标记，由父标签（如 hook, if）在其内容中解析处理
                if (in_array($node->name, self::PASSTHROUGH_TAGS, true)) {
                    // 获取标签配置，检查是否只定义了 tag-self-close
                    $tagConfig = $tags[$node->name] ?? $tags['w:' . $node->name] ?? [];
                    $isSelfCloseOnlyTag = !empty($tagConfig['tag-self-close']) 
                        && empty($tagConfig['tag']) 
                        && empty($tagConfig['tag-start']) 
                        && empty($tagConfig['tag-end']);
                    
                    // 对于只定义了 tag-self-close 的标签，强制输出自闭合形式
                    if ($isSelfCloseOnlyTag) {
                        $attrs = $node->rawAttributes ?? '';
                        $result[] = new TextNode($node->line, '<' . $node->name . $attrs . '/>');
                    } else {
                        $result[] = new TextNode($node->line, $this->buildOriginalTagMarkup($node));
                    }
                    continue;
                }
                
                // 编译属性值中的标签节点
                foreach ($node->attributes as $attr) {
                    if ($attr instanceof AttrNode && is_array($attr->value)) {
                        $attr->value = $this->compileAttributeValueNodes($attr->value, $tags, $template, $fileName);
                    }
                }
                
                $node->children = $this->compileNodes($node->children, $tags, $template, $fileName);
                if (isset($tags[$node->name])) {
                    $node->executionStage = $this->determineTagStage($node);
                    if ($node->executionStage === self::STAGE_COMPILE_TIME) {
                        $rendered = $this->renderTagNode($node, $tags, $template, $fileName);
                        $result = array_merge($result, $this->compileStringToNodes($rendered, $tags, $template, $fileName));
                        continue;
                    }
                }
            }
            $result[] = $node;
        }
        return $result;
    }
    
    /**
     * 编译属性值中的节点（递归处理标签节点）
     */
    private function compileAttributeValueNodes(array $nodes, array $tags, Template $template, string $fileName): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof TagNode) {
                // 递归编译属性值中的标签的属性
                foreach ($node->attributes as $attr) {
                    if ($attr instanceof AttrNode && is_array($attr->value)) {
                        $attr->value = $this->compileAttributeValueNodes($attr->value, $tags, $template, $fileName);
                    }
                }
                // 递归编译子节点
                $node->children = $this->compileNodes($node->children, $tags, $template, $fileName);
                
                if (isset($tags[$node->name])) {
                    $node->executionStage = $this->determineTagStage($node);
                    if ($node->executionStage === self::STAGE_COMPILE_TIME) {
                        // 编译期执行的标签，将其渲染结果转换为节点
                        $rendered = $this->renderTagNode($node, $tags, $template, $fileName);
                        // 渲染结果直接作为文本节点
                        if ($rendered !== '') {
                            $result[] = new TextNode($node->line, $rendered);
                        }
                        continue;
                    }
                }
            }
            $result[] = $node;
        }
        return $result;
    }

    private function compileStringToNodes(string $content, array $tags, Template $template, string $fileName): array
    {
        if ($content === '') {
            return [];
        }
        $tokens = $this->tokenizeTemplate($content);
        $ast = $this->parseTokensToAst($tokens, $tags);
        $ast = $this->compileAst($ast, $tags, $template, $fileName);
        $ast = $this->optimizeAst($ast);
        return $ast->children;
    }

    private function optimizeAst(ProgramNode $root): ProgramNode
    {
        $optimized = [];
        foreach ($root->children as $node) {
            if ($node instanceof TextNode && $node->value === '') {
                continue;
            }
            if ($node instanceof TextNode && !empty($optimized)) {
                $last = $optimized[count($optimized) - 1];
                if ($last instanceof TextNode) {
                    $last->value .= $node->value;
                    continue;
                }
            }
            $optimized[] = $node;
        }
        $root->children = $optimized;
        return $root;
    }

    private function resolveTagKey(TagNode $node, array $tagConfig): string
    {
        if ($node->tagKey) {
            return $node->tagKey;
        }
        if ($node->selfClosing) {
            if (!empty($tagConfig['tag-self-close-with-attrs']) && trim($node->rawAttributes ?? '') !== '') {
                return 'tag-self-close-with-attrs';
            }
            if (!empty($tagConfig['tag-self-close'])) {
                return 'tag-self-close';
            }
        }
        if (!empty($tagConfig['tag'])) {
            return 'tag';
        }
        if (!empty($tagConfig['tag-start']) && !empty($tagConfig['tag-end'])) {
            return 'tag-start';
        }
        // 如果标签只定义了 tag-self-close（如 <else/>），但没有被识别为自闭合标签，
        // 仍然应该使用 tag-self-close 类型，而不是默认的 'tag'
        if (!empty($tagConfig['tag-self-close'])) {
            return 'tag-self-close';
        }
        return 'tag';
    }

    private function renderTagNode(TagNode $node, array $tags, Template $template, string $fileName): string
    {
        $config = $tags[$node->name] ?? null;
        if ($config === null) {
            return $this->buildOriginalTagMarkup($node);
        }
        $tagKey = $this->resolveTagKey($node, $config);
        $rawAttributes = $node->rawAttributes ?? '';
        $content = $this->generatePhpFromNodes($node->children, $tags, $template, $fileName);
        $tagData = $this->buildTagData($node, $content);
        $attributes = $this->buildAttributesFromRaw($rawAttributes);

        if ($tagKey === 'tag-start' && !empty($config['tag-end'])) {
            $start = $config['callback']('tag-start', $config, $tagData, $attributes);
            $end = $config['callback']('tag-end', $config, $tagData, $attributes);
            
            // 对于 if 标签，需要将内容中的 <else/> 和 <elseif .../> 转换为 PHP 代码
            if ($node->name === 'if' || $node->name === 'w:if') {
                // 处理 <elseif condition="..."/>
                $content = preg_replace_callback(
                    '/<(?:w:)?elseif\s+condition\s*=\s*["\']([^"\']+)["\']\s*\/?>/i',
                    function ($matches) {
                        $condition = $this->varParser($this->checkVar($matches[1]));
                        return self::PHP_OPEN_TAG . 'php elseif(' . $condition . '):' . self::PHP_CLOSE_TAG;
                    },
                    $content
                );
                // 处理 <else/> 和 <else />
                $content = preg_replace('/<(?:w:)?else\s*\/?>/i', self::PHP_OPEN_TAG . 'php else:' . self::PHP_CLOSE_TAG, $content);
            }
            
            return $start . $content . $end;
        }

        return $config['callback']($tagKey, $config, $tagData, $attributes);
    }

    private function buildTagData(TagNode $node, string $content): array
    {
        if ($node->tagKey && ($node->tagKey === '@tag()' || $node->tagKey === '@tag{}')) {
            return [$node->raw ?? '', $node->inlineContent ?? ''];
        }
        return [$node->raw ?? $this->buildOriginalTagMarkup($node), $node->rawAttributes ?? '', $content];
    }

    private function buildOriginalTagMarkup(TagNode $node): string
    {
        $attrs = $node->rawAttributes ?? '';
        if ($node->selfClosing) {
            return '<' . $node->name . $attrs . '/>';
        }
        $content = $this->buildStringFromNodes($node->children);
        return '<' . $node->name . $attrs . '>' . $content . '</' . $node->name . '>';
    }

    private function generatePhpFromAst(ProgramNode $root, array $tags, Template $template, string $fileName): string
    {
        return $this->generatePhpFromNodes($root->children, $tags, $template, $fileName);
    }

    private function generatePhpFromNodes(array $nodes, array $tags, Template $template, string $fileName): string
    {
        $output = '';
        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $output .= $node->value;
            } elseif ($node instanceof PhpNode) {
                $output .= $node->code;
            } elseif ($node instanceof TagNode) {
                $config = $tags[$node->name] ?? null;
                if ($config === null) {
                    $output .= $this->buildOriginalTagMarkup($node);
                    continue;
                }
                $tagKey = $this->resolveTagKey($node, $config);
                $attrsExpr = $this->buildRuntimeAttributesExpression($node->attributes);
                $contentExpr = $this->buildChildrenBufferExpression($node->children, $tags, $template, $fileName);
                $rawAttrs = addslashes($node->rawAttributes ?? '');
                $inlineContent = addslashes($node->inlineContent ?? '');
                // 将 $this 赋值给 $_template 变量，以便在闭包中使用
                $output .= self::PHP_OPEN_TAG . 'php $_template = $this; echo $this->getTaglib()->renderRuntimeTag($this, \'' .
                    addslashes($node->name) . '\', \'' . $tagKey . '\', ' . $attrsExpr . ', ' . $contentExpr .
                    ', ' . var_export($fileName, true) . ', \'' . $rawAttrs . '\', \'' . $inlineContent . '\');' .
                    self::PHP_CLOSE_TAG;
            }
        }
        return $output;
    }

    private function buildRuntimeAttributesExpression(array $attributes): string
    {
        if (empty($attributes)) {
            return '[]';
        }
        $parts = [];
        foreach ($attributes as $attr) {
            if (!$attr instanceof AttrNode) {
                continue;
            }
            $expr = $this->buildNodesExpression($attr->value);
            $parts[] = '\'' . addslashes($attr->name) . '\' => ' . $expr;
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function buildNodesExpression($nodes): string
    {
        if ($nodes instanceof AstNode) {
            $nodes = [$nodes];
        }
        $parts = [];
        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $parts[] = '\'' . addslashes($node->value) . '\'';
            } elseif ($node instanceof PhpNode) {
                $parts[] = $node->expression !== null ? '(' . $node->expression . ')' : '\'\'';
            }
        }
        if (empty($parts)) {
            return '\'\'';
        }
        return implode(' . ', $parts);
    }

    private function buildChildrenBufferExpression(array $children, array $tags, Template $template, string $fileName): string
    {
        $childPhp = $this->generatePhpFromNodes($children, $tags, $template, $fileName);
        // 在闭包内部提取模板数据和当前作用域的局部变量，使变量可在闭包中访问
        // 使用 $_template 变量（在调用 renderRuntimeTag 之前已赋值），因为不能使用 use ($this)
        // 使用 get_defined_vars() 捕获当前作用域的所有变量（包括 foreach 循环变量如 $page, $index 等）
        // 注意：先提取模板数据，再提取局部变量（EXTR_SKIP 确保模板数据优先不被覆盖）
        // 过滤掉可能导致问题的特殊变量（如 $this, $_template 等）
        return '(function($_local_vars) use ($_template) { ' .
            '$_excluded = [\'this\', \'_template\', \'_local_vars\']; ' .
            '$_local_vars = array_diff_key($_local_vars, array_flip($_excluded)); ' .
            'extract($_template->getData(), EXTR_SKIP); ' .
            'extract($_local_vars, EXTR_SKIP); ' .
            'ob_start(); ' . self::PHP_CLOSE_TAG . $childPhp . self::PHP_OPEN_TAG . 'php return ob_get_clean(); })(get_defined_vars())';
    }

    public function renderRuntimeTag(
        Template $template,
        string $tagName,
        string $tagKey,
        array $attributes,
        string $content = '',
        string $fileName = '',
        string $rawAttributes = '',
        string $inlineContent = ''
    ): string {
        $tags = $this->getTags($template, $fileName, $content);
        $config = $tags[$tagName] ?? null;
        if ($config === null) {
            return $content;
        }
        $tagData = ($tagKey === '@tag()' || $tagKey === '@tag{}')
            ? [$inlineContent, $inlineContent]
            : [$this->buildRuntimeRawTag($tagName, $rawAttributes, $content, $tagKey), $rawAttributes, $content];

        return $config['callback']($tagKey, $config, $tagData, $attributes);
    }

    private function buildRuntimeRawTag(string $tagName, string $rawAttributes, string $content, string $tagKey): string
    {
        if ($tagKey === 'tag-self-close' || $tagKey === 'tag-self-close-with-attrs') {
            return '<' . $tagName . $rawAttributes . '/>';
        }
        return '<' . $tagName . $rawAttributes . '>' . $content . '</' . $tagName . '>';
    }

    public function tagReplace(Template &$template, string &$content, string &$fileName = ''): array|string
    {
        if (PROD) {
            $content = $this->removeHtmlComments($content);
        }

        $tags = $this->getTags($template, $fileName, $content);
        $tokens = $this->tokenizeTemplate($content);
        $ast = $this->parseTokensToAst($tokens, $tags);
        $ast = $this->compileAst($ast, $tags, $template, $fileName);
        $ast = $this->optimizeAst($ast);
        $content = $this->generatePhpFromAst($ast, $tags, $template, $fileName);

        return $content;
    }
}

class AstToken
{
    public string $type;
    public string $value;
    public int $line;
    public int $column;
    public array $meta;

    public function __construct(string $type, string $value, int $line, int $column, array $meta = [])
    {
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
        $this->column = $column;
        $this->meta = $meta;
    }
}

abstract class AstNode
{
    public int $line;

    public function __construct(int $line)
    {
        $this->line = $line;
    }
}

class ProgramNode extends AstNode
{
    public array $children = [];
}

class FragmentNode extends AstNode
{
    public array $children = [];
}

class TextNode extends AstNode
{
    public string $value;

    public function __construct(int $line, string $value)
    {
        parent::__construct($line);
        $this->value = $value;
    }
}

class PhpNode extends AstNode
{
    public string $code;
    public ?string $expression;

    public function __construct(int $line, string $code, ?string $expression)
    {
        parent::__construct($line);
        $this->code = $code;
        $this->expression = $expression;
    }
}

class TagNode extends AstNode
{
    public string $name;
    public array $attributes = [];
    public array $children = [];
    public bool $selfClosing = false;
    public ?string $tagKey = null;
    public ?string $rawAttributes = null;
    public ?string $inlineContent = null;
    public ?string $executionStage = null;
    public $handler = null;
    public ?string $raw = null;

    public function __construct(int $line, string $name, array $attributes)
    {
        parent::__construct($line);
        $this->name = $name;
        $this->attributes = $attributes;
    }
}

class AttrNode extends AstNode
{
    public string $name;
    public array $value;

    public function __construct(int $line, string $name, array $value)
    {
        parent::__construct($line);
        $this->name = $name;
        $this->value = $value;
    }
}
