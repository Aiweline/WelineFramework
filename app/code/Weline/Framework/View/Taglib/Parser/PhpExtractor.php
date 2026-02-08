<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | PHP 代码提取器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Parser
 */

namespace Weline\Framework\View\Taglib\Parser;

/**
 * PHP 代码提取器
 * 
 * 使用 PHP 内置 token_get_all() 精确识别 PHP 代码块，
 * 将其替换为占位符，便于后续框架标签解析。
 */
final class PhpExtractor
{
    /**
     * 占位符前缀
     */
    private const PREFIX = '__PHP_';

    /**
     * 占位符后缀
     */
    private const SUFFIX = '__';

    /**
     * 占位符计数器
     */
    private int $counter = 0;

    /**
     * 占位符到 PHP 代码的映射
     * @var array<string, array{code: string, expression: string, isEcho: bool, line: int}>
     */
    private array $placeholders = [];

    /**
     * 重置内部状态，允许实例复用（避免每次编译创建新实例）
     */
    public function reset(): void
    {
        $this->counter = 0;
        $this->placeholders = [];
    }

    /**
     * 提取 PHP 代码块，返回清洁内容
     * 
     * 使用 token_get_all() 精确解析，正确处理：
     * - 字符串中的闭合标签
     * - heredoc/nowdoc 语法
     * - 嵌套引号
     * 
     * @param string $content 模板内容
     * @return string 替换占位符后的清洁内容
     */
    public function extract(string $content): string
    {
        // 如果没有 PHP 代码，直接返回
        $phpOpenTag = '<' . '?';
        if (!str_contains($content, $phpOpenTag)) {
            return $content;
        }

        // 添加包装使 token_get_all 能正确解析
        $wrapped = $phpOpenTag . 'php ?' . '>' . $content;
        
        try {
            $tokens = token_get_all($wrapped, TOKEN_PARSE);
        } catch (\ParseError $e) {
            // 如果解析失败，返回原始内容
            return $content;
        }

        $result = '';
        $inPhp = false;
        $phpBuffer = '';
        $phpStartLine = 1;
        $isEcho = false;
        $skipFirstClose = true;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;

                // 跳过我们添加的 PHP 包装标签
                if ($skipFirstClose) {
                    if ($id === T_OPEN_TAG) {
                        continue;
                    }
                    if ($id === T_CLOSE_TAG) {
                        $skipFirstClose = false;
                        continue;
                    }
                    continue;
                }

                switch ($id) {
                    case T_OPEN_TAG:
                        $inPhp = true;
                        $isEcho = false;
                        $phpBuffer = $text;
                        $phpStartLine = $line;
                        break;

                    case T_OPEN_TAG_WITH_ECHO:
                        $inPhp = true;
                        $isEcho = true;
                        $phpBuffer = $text;
                        $phpStartLine = $line;
                        break;

                    case T_CLOSE_TAG:
                        if ($inPhp) {
                            $phpBuffer .= $text;
                            $result .= $this->createPlaceholder($phpBuffer, $phpStartLine, $isEcho);
                            $phpBuffer = '';
                            $inPhp = false;
                        } else {
                            $result .= $text;
                        }
                        break;

                    case T_INLINE_HTML:
                        if ($inPhp) {
                            $phpBuffer .= $text;
                        } else {
                            $result .= $text;
                        }
                        break;

                    default:
                        if ($inPhp) {
                            $phpBuffer .= $text;
                        } else {
                            $result .= $text;
                        }
                }
            } else {
                // 单字符 token（如 ; { } 等）
                if ($inPhp) {
                    $phpBuffer .= $token;
                } else {
                    $result .= $token;
                }
            }
        }

        // 处理未闭合的 PHP 块
        if ($phpBuffer !== '') {
            $result .= $this->createPlaceholder($phpBuffer, $phpStartLine, $isEcho);
        }

        return $result;
    }

    /**
     * 流式提取 PHP 代码块
     * 
     * 使用 Generator 减少内存占用
     * 
     * @param string $content 模板内容
     * @return \Generator<string> 清洁内容片段
     */
    public function extractStream(string $content): \Generator
    {
        $phpOpenTag = '<' . '?';
        if (!str_contains($content, $phpOpenTag)) {
            yield $content;
            return;
        }

        $wrapped = $phpOpenTag . 'php ?' . '>' . $content;
        
        try {
            $tokens = token_get_all($wrapped, TOKEN_PARSE);
        } catch (\ParseError $e) {
            yield $content;
            return;
        }

        $inPhp = false;
        $phpBuffer = '';
        $phpStartLine = 1;
        $isEcho = false;
        $skipFirstClose = true;
        $textBuffer = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;

                if ($skipFirstClose) {
                    if ($id === T_OPEN_TAG) {
                        continue;
                    }
                    if ($id === T_CLOSE_TAG) {
                        $skipFirstClose = false;
                        continue;
                    }
                    continue;
                }

                switch ($id) {
                    case T_OPEN_TAG:
                        if ($textBuffer !== '') {
                            yield $textBuffer;
                            $textBuffer = '';
                        }
                        $inPhp = true;
                        $isEcho = false;
                        $phpBuffer = $text;
                        $phpStartLine = $line;
                        break;

                    case T_OPEN_TAG_WITH_ECHO:
                        if ($textBuffer !== '') {
                            yield $textBuffer;
                            $textBuffer = '';
                        }
                        $inPhp = true;
                        $isEcho = true;
                        $phpBuffer = $text;
                        $phpStartLine = $line;
                        break;

                    case T_CLOSE_TAG:
                        if ($inPhp) {
                            $phpBuffer .= $text;
                            yield $this->createPlaceholder($phpBuffer, $phpStartLine, $isEcho);
                            $phpBuffer = '';
                            $inPhp = false;
                        } else {
                            $textBuffer .= $text;
                        }
                        break;

                    case T_INLINE_HTML:
                        if (!$inPhp) {
                            $textBuffer .= $text;
                        } else {
                            $phpBuffer .= $text;
                        }
                        break;

                    default:
                        if ($inPhp) {
                            $phpBuffer .= $text;
                        } else {
                            $textBuffer .= $text;
                        }
                }
            } else {
                if ($inPhp) {
                    $phpBuffer .= $token;
                } else {
                    $textBuffer .= $token;
                }
            }
        }

        // 输出剩余内容
        if ($phpBuffer !== '') {
            yield $this->createPlaceholder($phpBuffer, $phpStartLine, $isEcho);
        }
        if ($textBuffer !== '') {
            yield $textBuffer;
        }
    }

    /**
     * 恢复 PHP 代码
     * 
     * @param string $content 包含占位符的内容
     * @return string 恢复后的原始内容
     */
    public function restore(string $content): string
    {
        if (empty($this->placeholders)) {
            return $content;
        }

        // 使用 strtr 一次性替换所有占位符
        $replacements = [];
        foreach ($this->placeholders as $placeholder => $data) {
            $replacements[$placeholder] = $data['code'];
        }

        return strtr($content, $replacements);
    }

    /**
     * 获取占位符对应的纯表达式
     * 
     * @param string $placeholder 占位符
     * @return string|null PHP 表达式（不含标签包装）
     */
    public function getExpression(string $placeholder): ?string
    {
        return $this->placeholders[$placeholder]['expression'] ?? null;
    }

    /**
     * 获取占位符信息
     * 
     * @param string $placeholder 占位符
     * @return array{code: string, expression: string, isEcho: bool, line: int}|null
     */
    public function getPlaceholderInfo(string $placeholder): ?array
    {
        return $this->placeholders[$placeholder] ?? null;
    }

    /**
     * 检查字符串是否为占位符
     */
    public function isPlaceholder(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) 
            && str_ends_with($value, self::SUFFIX)
            && isset($this->placeholders[$value]);
    }

    /**
     * 检查字符串是否包含占位符
     */
    public function hasPlaceholder(string $value): bool
    {
        return str_contains($value, self::PREFIX);
    }

    /**
     * 获取所有占位符
     * 
     * @return array<string, array{code: string, expression: string, isEcho: bool, line: int}>
     */
    public function getPlaceholders(): array
    {
        return $this->placeholders;
    }

    /**
     * 创建占位符并记录映射
     */
    private function createPlaceholder(string $phpCode, int $line, bool $isEcho): string
    {
        $placeholder = self::PREFIX . $this->counter++ . self::SUFFIX;
        
        // 提取纯表达式（移除 PHP 标签包装）
        $expression = $this->extractExpression($phpCode);
        
        $this->placeholders[$placeholder] = [
            'code' => $phpCode,
            'expression' => $expression,
            'isEcho' => $isEcho,
            'line' => $line,
        ];

        return $placeholder;
    }

    /**
     * 从 PHP 代码中提取纯表达式
     */
    private function extractExpression(string $phpCode): string
    {
        // 移除 PHP 开始标签
        $pattern = '/^<' . '\?(?:php|=)\s*/i';
        $expr = preg_replace($pattern, '', $phpCode);
        
        // 移除尾部 PHP 结束标签
        $closePattern = '/\s*\?' . '>$/';
        $expr = preg_replace($closePattern, '', $expr);
        
        // 移除尾部分号
        $expr = rtrim(trim($expr), ';');
        
        return $expr;
    }

    /**
     * 获取统计信息
     * 
     * @return array{count: int, totalSize: int}
     */
    public function stats(): array
    {
        $totalSize = 0;
        foreach ($this->placeholders as $data) {
            $totalSize += strlen($data['code']);
        }
        return [
            'count' => count($this->placeholders),
            'totalSize' => $totalSize,
        ];
    }
}
