<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 模板词法分析器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Parser
 */

namespace Weline\Framework\View\Taglib\Parser;

// 显式加载 TokenType（与 Token 在同一文件）
require_once __DIR__ . '/Token.php';

/**
 * 模板词法分析器
 *
 * 将模板字符串转换为 Token 流
 * 使用 Generator 实现流式处理，内存 O(1)
 * 分支预测优化：按频率排序条件判断
 */
final class Tokenizer
{
    /**
     * 标签匹配正则
     *
     * 匹配：<tagname attrs> 或 </tagname> 或 <tagname attrs/>
     */
    private const TAG_PATTERN = '/<(\/)?([a-zA-Z][\w:.-]*)((?:\s+[^>\/]*(?:"[^"]*"|\'[^\']*\')?)*)\s*(\/)?>/s';

    /**
     * 内联资源标签名（用于 @ 符号的内联语法）
     * 这些标签在词法分析阶段统一识别，无需后处理
     */
    private const INLINE_ASSET_TAGS = [
        'static',
        'url',
        'frontend-url',
        'backend-url',
        'api',
        'frontend-api',
        'backend-api',
        'template',
        'include',
        'lang',
        'trans',
    ];

    /**
     * 内联标签匹配正则
     *
     * 匹配：@tagname(content) 或 @tagname{content}
     * 例如：@template(Weline_Admin::common/head.phtml)
     * 支持带连字符的标签名，如 @backend-url
     */
    private const INLINE_TAG_PATTERN = '/@([a-zA-Z_][\w-]*)(?:\(([^()]*(?:\([^()]*\)[^()]*)*)\)|\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\})/';

    /**
     * 占位符匹配正则
     */
    private const PLACEHOLDER_PATTERN = '/__PHP_(\d+)__/';

    /**
     * 双花括号变量匹配正则
     * 匹配：{{variable}} 或 {{ variable }} 或 {{$variable}}
     */
    private const VARIABLE_PATTERN = '/\{\{\s*([^}]+?)\s*\}\}/';

    /**
     * 框架标签名列表（用于过滤非框架标签）
     * @var array<string, bool>
     */
    private array $frameworkTags = [];

    /**
     * 内联资源标签映射（用于快速查找）
     * @var array<string, bool>
     */
    private static ?array $inlineAssetTagsMap = null;

    /**
     * 设置框架标签列表
     * 
     * @param array<string> $tags 标签名列表
     */
    public function setFrameworkTags(array $tags): void
    {
        $this->frameworkTags = array_fill_keys($tags, true);
        // Debug: 检查 template 是否在白名单中
        if (!isset($this->frameworkTags['template'])) {
            // error_log("Tokenizer: 'template' tag is missing from framework tags whitelist!");
        }
    }

    /**
     * 流式 tokenize
     * 
     * 使用 Generator 减少内存占用
     * 
     * @param string $content 模板内容（已替换 PHP 占位符）
     * @return \Generator<Token>
     */
    public function tokenize(string $content): \Generator
    {
        $offset = 0;
        $line = 1;
        $length = strlen($content);

        while ($offset < $length) {
            // 分支预测优化：Text 最常见（80%+），优先匹配
            
            // 查找下一个标签、内联标签、占位符或双花括号变量
            $nextTagPos = strpos($content, '<', $offset);
            $nextInlinePos = strpos($content, '@', $offset);
            $nextPhpPos = strpos($content, '__PHP_', $offset);
            $nextVarPos = strpos($content, '{{', $offset);

            // 计算最近的特殊位置
            $nextPos = $length;
            $nextType = null;

            if ($nextTagPos !== false && $nextTagPos < $nextPos) {
                $nextPos = $nextTagPos;
                $nextType = 'tag';
            }
            if ($nextInlinePos !== false && $nextInlinePos < $nextPos) {
                $nextPos = $nextInlinePos;
                $nextType = 'inline';
            }
            if ($nextPhpPos !== false && $nextPhpPos < $nextPos) {
                $nextPos = $nextPhpPos;
                $nextType = 'php';
            }
            if ($nextVarPos !== false && $nextVarPos < $nextPos) {
                $nextPos = $nextVarPos;
                $nextType = 'variable';
            }

            // 处理标签/占位符前的文本（最常见情况）
            if ($nextPos > $offset) {
                $text = substr($content, $offset, $nextPos - $offset);
                $line += substr_count($text, "\n");
                yield new Token(TokenType::Text, $text, $line);
                $offset = $nextPos;
                
                if ($offset >= $length) {
                    break;
                }
            }

            // 处理占位符
            if ($nextType === 'php') {
                if (preg_match(self::PLACEHOLDER_PATTERN, $content, $m, 0, $offset) && strpos($content, $m[0], $offset) === $offset) {
                    yield new Token(TokenType::Placeholder, $m[0], $line);
                    $offset += strlen($m[0]);
                    continue;
                }
            }

            // 处理双花括号变量 {{variable}}
            if ($nextType === 'variable') {
                if (preg_match(self::VARIABLE_PATTERN, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $m[0][1];
                    
                    if ($matchStart === $offset) {
                        $fullMatch = $m[0][0];
                        $varPath = trim($m[1][0]);
                        
                        yield new Token(TokenType::Variable, $varPath, $line, [
                            'fullMatch' => $fullMatch,
                        ]);
                        
                        $line += substr_count($fullMatch, "\n");
                        $offset += strlen($fullMatch);
                        continue;
                    }
                }
                
                // 不是有效变量（只有单个 { 或未闭合），作为文本处理
                $text = substr($content, $offset, 2); // 输出 {{
                yield new Token(TokenType::Text, $text, $line);
                $offset += 2;
                continue;
            }

            // 处理内联标签 @template(...) 或 @template{...} 格式
            if ($nextType === 'inline') {
                if (preg_match(self::INLINE_TAG_PATTERN, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $m[0][1];
                    
                    if ($matchStart === $offset) {
                        $fullMatch = $m[0][0];
                        $tagName = $m[1][0];
                        // 提取内容：m[2] 是小括号内容，m[3] 是大括号内容
                        $tagContent = ($m[2][0] ?? '') !== '' ? $m[2][0] : ($m[3][0] ?? '');

                        // 判断是否为框架标签
                        if ($this->isFrameworkTag($tagName)) {
                            yield new Token(TokenType::InlineTag, $tagName, $line, [
                                'content' => $tagContent,
                                'fullMatch' => $fullMatch,
                            ]);
                        } else {
                            // 非框架标签作为文本处理
                            yield new Token(TokenType::Text, $fullMatch, $line);
                        }

                        $line += substr_count($fullMatch, "\n");
                        $offset += strlen($fullMatch);
                        continue;
                    }
                }

                // 不是有效内联标签，作为文本处理
                $text = substr($content, $offset, 1);
                yield new Token(TokenType::Text, $text, $line);
                $offset++;
                continue;
            }

            // 处理标签
            if ($nextType === 'tag') {
                if (preg_match(self::TAG_PATTERN, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $m[0][1];
                    
                    // 确保匹配从当前位置开始
                    if ($matchStart === $offset) {
                        $fullMatch = $m[0][0];
                        $isClose = ($m[1][0] ?? '') !== '';
                        $tagName = $m[2][0];
                        $rawAttrs = trim($m[3][0] ?? '');
                        $selfClose = ($m[4][0] ?? '') === '/';

                        // 判断是否为框架标签
                        $isFrameworkTag = $this->isFrameworkTag($tagName);

                        if ($isFrameworkTag) {
                            $type = match (true) {
                                $isClose => TokenType::CloseTag,
                                $selfClose => TokenType::SelfCloseTag,
                                default => TokenType::OpenTag,
                            };

                            yield new Token($type, $tagName, $line, [
                                'rawAttrs' => $rawAttrs,
                                'fullMatch' => $fullMatch,
                            ]);
                        } else {
                            // 非框架标签：检查属性中是否包含双花括号变量或内联标签
                            // 如果包含，需要分割生成多个 Token
                            if (str_contains($fullMatch, '{{') || str_contains($fullMatch, '@')) {
                                yield from $this->tokenizeTextWithVariables($fullMatch, $line);
                            } else {
                                yield new Token(TokenType::Text, $fullMatch, $line);
                            }
                        }

                        $line += substr_count($fullMatch, "\n");
                        $offset += strlen($fullMatch);
                        continue;
                    }
                }

                // 不是有效标签，作为文本处理
                $text = substr($content, $offset, 1);
                yield new Token(TokenType::Text, $text, $line);
                $offset++;
                continue;
            }

            // 无特殊内容，处理剩余文本
            if ($nextType === null && $offset < $length) {
                $text = substr($content, $offset);
                yield new Token(TokenType::Text, $text, $line);
                break;
            }
        }
    }

    /**
     * 将 Token 流转换为数组
     */
    public function tokenizeToArray(string $content): array
    {
        return iterator_to_array($this->tokenize($content), false);
    }
    
    /**
     * 在文本中扫描内联标签和双花括号变量并生成混合 Token
     * 
     * @param string $text 待扫描的文本
     * @param int $line 起始行号
     * @return \Generator<Token>
     */
    private function tokenizeTextWithVariables(string $text, int $line): \Generator
    {
        $offset = 0;
        $length = strlen($text);
        
        while ($offset < $length) {
            // 查找下一个双花括号和内联标签
            $nextVarPos = strpos($text, '{{', $offset);
            $nextInlinePos = strpos($text, '@', $offset);
            
            // 计算最近的特殊位置
            $nextPos = $length;
            $nextType = null;
            
            if ($nextVarPos !== false && $nextVarPos < $nextPos) {
                $nextPos = $nextVarPos;
                $nextType = 'variable';
            }
            if ($nextInlinePos !== false && $nextInlinePos < $nextPos) {
                $nextPos = $nextInlinePos;
                $nextType = 'inline';
            }
            
            // 没有更多特殊内容
            if ($nextType === null) {
                if ($offset < $length) {
                    $remaining = substr($text, $offset);
                    yield new Token(TokenType::Text, $remaining, $line);
                    $line += substr_count($remaining, "\n");
                }
                break;
            }
            
            // 输出特殊内容前的文本
            if ($nextPos > $offset) {
                $beforeText = substr($text, $offset, $nextPos - $offset);
                yield new Token(TokenType::Text, $beforeText, $line);
                $line += substr_count($beforeText, "\n");
                $offset = $nextPos;
            }
            
            // 处理内联标签 @static(...)
            if ($nextType === 'inline') {
                if (preg_match(self::INLINE_TAG_PATTERN, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $m[0][1];
                    
                    if ($matchStart === $offset) {
                        $fullMatch = $m[0][0];
                        $tagName = $m[1][0];
                        // 提取内容：m[2] 是小括号内容，m[3] 是大括号内容
                        $tagContent = ($m[2][0] ?? '') !== '' ? $m[2][0] : ($m[3][0] ?? '');
                        
                        // 判断是否为框架标签
                        if ($this->isFrameworkTag($tagName)) {
                            yield new Token(TokenType::InlineTag, $tagName, $line, [
                                'content' => $tagContent,
                                'fullMatch' => $fullMatch,
                            ]);
                        } else {
                            // 非框架标签作为文本处理
                            yield new Token(TokenType::Text, $fullMatch, $line);
                        }
                        
                        $line += substr_count($fullMatch, "\n");
                        $offset += strlen($fullMatch);
                        continue;
                    }
                }
                
                // 不是有效内联标签，输出 @ 作为文本
                yield new Token(TokenType::Text, '@', $line);
                $offset++;
                continue;
            }
            
            // 处理双花括号变量
            if ($nextType === 'variable') {
                if (preg_match(self::VARIABLE_PATTERN, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                    $matchStart = $m[0][1];
                    
                    if ($matchStart === $offset) {
                        $fullMatch = $m[0][0];
                        $varPath = trim($m[1][0]);
                        
                        yield new Token(TokenType::Variable, $varPath, $line, [
                            'fullMatch' => $fullMatch,
                        ]);
                        
                        $line += substr_count($fullMatch, "\n");
                        $offset += strlen($fullMatch);
                        continue;
                    }
                }
                
                // 不是有效变量，输出 {{ 作为文本
                yield new Token(TokenType::Text, '{{', $line);
                $offset += 2;
            }
        }
    }

    /**
     * 获取内联资源标签映射
     */
    private static function getInlineAssetTagsMap(): array
    {
        if (self::$inlineAssetTagsMap === null) {
            self::$inlineAssetTagsMap = array_fill_keys(self::INLINE_ASSET_TAGS, true);
        }
        return self::$inlineAssetTagsMap;
    }

    /**
     * 判断是否为内联资源标签
     */
    public function isInlineAssetTag(string $tagName): bool
    {
        return isset(self::getInlineAssetTagsMap()[$tagName]);
    }

    /**
     * 判断是否为框架标签
     */
    private function isFrameworkTag(string $tagName): bool
    {
        // 首先检查是否为内联资源标签
        if ($this->isInlineAssetTag($tagName)) {
            return true;
        }

        // 如果设置了白名单，检查是否在白名单中
        if (!empty($this->frameworkTags)) {
            // 检查完整标签名或命名空间前缀
            if (isset($this->frameworkTags[$tagName])) {
                return true;
            }

            // 检查命名空间（如 w:seo:account:select）
            $parts = explode(':', $tagName);
            if (count($parts) > 1) {
                // 检查顶级命名空间
                return isset($this->frameworkTags[$parts[0]]);
            }

            return false;
        }

        // 默认规则：以特定前缀开头的视为框架标签
        // 常见的框架标签前缀：block, lang, if, foreach, for, switch, w:, etc.
        $builtinTags = [
            'block', 'lang', 'if', 'else', 'elseif', 'foreach', 'for',
            'switch', 'case', 'default', 'include', 'template', 'slot',
            'component', 'yield', 'section', 'show', 'parent', 'push', 'hook',
            'stack', 'once', 'php', 'verbatim', 'json', 'method', 'csrf',
            'css', 'js', 'static',
        ];

        if (in_array($tagName, $builtinTags, true)) {
            return true;
        }

        // 带命名空间的标签（如 w:xxx）
        if (str_contains($tagName, ':')) {
            return true;
        }

        return false;
    }

    /**
     * 获取所有内联资源标签名列表
     */
    public static function getInlineAssetTags(): array
    {
        return self::INLINE_ASSET_TAGS;
    }
}
