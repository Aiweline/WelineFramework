<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 构建器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Parser
 */

namespace Weline\Framework\View\Taglib\Parser;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TextNode,
    PhpPlaceholder,
    TagNode,
    AttrNode,
    NodePool,
    StringInterner
};

/**
 * AST 构建器
 * 
 * 将 Token 流构建为 AST
 * 使用 NodePool 复用节点对象
 */
final class AstBuilder
{
    /**
     * PHP 提取器（用于获取占位符信息）
     */
    private ?PhpExtractor $phpExtractor = null;

    /**
     * 设置 PHP 提取器
     */
    public function setPhpExtractor(PhpExtractor $extractor): void
    {
        $this->phpExtractor = $extractor;
    }

    /**
     * 从 Token 流构建 AST
     * 
     * @param iterable<Token> $tokens Token 流或数组
     * @param string $fileName 源文件名
     * @return ProgramNode AST 根节点
     */
    public function build(iterable $tokens, string $fileName = ''): ProgramNode
    {
        // 转换为数组以便多次遍历
        $tokenArray = $tokens instanceof \Generator 
            ? iterator_to_array($tokens, false) 
            : (array)$tokens;

        $children = $this->buildNodes($tokenArray, 0, count($tokenArray));

        return NodePool::programNode(1, $children, $fileName);
    }

    /**
     * 构建节点列表
     * 
     * @param array<Token> $tokens Token 数组
     * @param int $start 起始索引
     * @param int $end 结束索引
     * @return array<Node>
     */
    private function buildNodes(array $tokens, int $start, int $end): array
    {
        $nodes = [];
        $i = $start;

        while ($i < $end) {
            $token = $tokens[$i];

            // 分支预测优化：按频率排序
            
            // 1. 文本节点（最常见）
            if ($token->type === TokenType::Text) {
                $nodes[] = NodePool::textNode($token->line, $token->value);
                $i++;
                continue;
            }

            // 2. PHP 占位符节点
            if ($token->type === TokenType::Placeholder) {
                $nodes[] = $this->buildPlaceholderNode($token);
                $i++;
                continue;
            }

            // 2.5. 双花括号变量节点 {{variable}}
            if ($token->type === TokenType::Variable) {
                $nodes[] = $this->buildVariableNode($token);
                $i++;
                continue;
            }

            // 3. 内联标签 @template(...)
            if ($token->type === TokenType::InlineTag) {
                $nodes[] = $this->buildInlineTagNode($token);
                $i++;
                continue;
            }

            // 4. 自闭合标签
            if ($token->type === TokenType::SelfCloseTag) {
                $attrs = $this->parseAttributes($token->getMeta('rawAttrs', ''), $token->line);
                $nodes[] = NodePool::tagNode(
                    $token->line,
                    $token->value,
                    $attrs,
                    [],
                    '',
                    true
                );
                $i++;
                continue;
            }

            // 5. 开始标签
            if ($token->type === TokenType::OpenTag) {
                $tagName = $token->value;
                $attrs = $this->parseAttributes($token->getMeta('rawAttrs', ''), $token->line);
                
                // 查找对应的结束标签
                $closeIndex = $this->findClosingTag($tokens, $i + 1, $end, $tagName);
                
                if ($closeIndex !== null) {
                    // 递归构建子节点
                    $children = $this->buildNodes($tokens, $i + 1, $closeIndex);
                    $nodes[] = NodePool::tagNode(
                        $token->line,
                        $tagName,
                        $attrs,
                        $children,
                        $this->extractRawContent($tokens, $i + 1, $closeIndex),
                        false
                    );
                    $i = $closeIndex + 1;
                } else {
                    // 未找到结束标签，作为自闭合处理
                    $nodes[] = NodePool::tagNode(
                        $token->line,
                        $tagName,
                        $attrs,
                        [],
                        '',
                        true
                    );
                    $i++;
                }
                continue;
            }

            // 6. 结束标签（通常不应该单独出现）
            if ($token->type === TokenType::CloseTag) {
                // 跳过孤立的结束标签
                $i++;
                continue;
            }

            $i++;
        }

        return $nodes;
    }

    /**
     * 构建 PHP 占位符节点
     */
    private function buildPlaceholderNode(Token $token): PhpPlaceholder
    {
        $placeholder = $token->value;
        $expression = '';
        $originalCode = '';
        $isEcho = true;

        if ($this->phpExtractor !== null) {
            $info = $this->phpExtractor->getPlaceholderInfo($placeholder);
            if ($info !== null) {
                $expression = $info['expression'];
                $originalCode = $info['code'];
                $isEcho = $info['isEcho'];
            }
        }

        return NodePool::phpPlaceholder($token->line, $placeholder, $expression, $originalCode, $isEcho);
    }

    /**
     * 构建双花括号变量节点 {{variable}}
     */
    private function buildVariableNode(Token $token): PhpPlaceholder
    {
        $varPath = $token->value;
        $fullMatch = $token->getMeta('fullMatch', '{{' . $varPath . '}}');
        
        $placeholder = '__VAR_' . md5($varPath) . '__';
        
        // 生成 PHP 代码用于变量输出
        // 使用 Taglib 的 varParser 方法解析变量路径
        // 这里直接生成占位符，后续由 CodeGenerator 处理
        return NodePool::phpPlaceholder(
            $token->line,
            $placeholder,   // 唯一占位符
            $varPath,       // 表达式
            $fullMatch,     // 原始代码
            true            // 是 echo 类型
        );
    }

    /**
     * 构建内联标签节点 @template(...)
     */
    private function buildInlineTagNode(Token $token): TagNode
    {
        $tagName = $token->value;
        $content = $token->getMeta('content', '');
        
        // 将内联标签内容转换为属性
        // @template(Weline_Admin::common/head.phtml) => <template value="Weline_Admin::common/head.phtml"/>
        $attrs = [];
        
        // 尝试解析为键值对属性
        $parsedAttrs = $this->parseInlineContent($content, $token->line);
        if (!empty($parsedAttrs)) {
            $attrs = $parsedAttrs;
        } else {
            // 作为单一值属性处理
            if ($content !== '') {
                $valueNodes = $this->parseAttributeValue($content, $token->line);
                $attrs[] = NodePool::attrNode($token->line, 'value', $valueNodes, $content);
            }
        }

        return NodePool::tagNode(
            $token->line,
            $tagName,
            $attrs,
            [],
            $content,
            true  // 内联标签都是自闭合的
        );
    }

    /**
     * 解析内联标签内容为属性列表
     * 
     * 支持格式：
     * - key=value key2=value2
     * - key="value" key2='value2'
     * 
     * @return array<AttrNode>
     */
    private function parseInlineContent(string $content, int $line): array
    {
        // 检查是否包含 = 号，如果包含则尝试解析为属性
        if (!str_contains($content, '=')) {
            return [];
        }

        return $this->parseAttributes($content, $line);
    }

    /**
     * 查找匹配的结束标签
     * 
     * @return int|null 结束标签的索引，未找到返回 null
     */
    private function findClosingTag(array $tokens, int $start, int $end, string $tagName): ?int
    {
        $depth = 1;

        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];

            if ($token->type === TokenType::OpenTag && $token->value === $tagName) {
                $depth++;
            } elseif ($token->type === TokenType::CloseTag && $token->value === $tagName) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 提取原始内容（用于某些标签）
     */
    private function extractRawContent(array $tokens, int $start, int $end): string
    {
        $content = '';
        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            if ($token->type === TokenType::Text) {
                $content .= $token->value;
            } elseif ($token->type === TokenType::Placeholder) {
                $content .= $token->value;
            } else {
                $content .= $token->getMeta('fullMatch', '');
            }
        }
        return $content;
    }

    /**
     * 解析属性字符串
     * 
     * @param string $rawAttrs 原始属性字符串
     * @param int $line 行号
     * @return array<AttrNode>
     */
    private function parseAttributes(string $rawAttrs, int $line): array
    {
        if ($rawAttrs === '') {
            return [];
        }

        $attrs = [];
        
        // 匹配属性：name="value" 或 name='value' 或 name=value 或 name
        $pattern = '/([a-zA-Z_][\w:.-]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/';
        
        if (preg_match_all($pattern, $rawAttrs, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = $m[1];
                // 优先级：双引号值 > 单引号值 > 无引号值 > 空
                // 注意：空字符串也是有效值，需要检查 isset 而不是 ??
                $value = '';
                if (isset($m[2]) && $m[2] !== '') {
                    $value = $m[2];
                } elseif (isset($m[3]) && $m[3] !== '') {
                    $value = $m[3];
                } elseif (isset($m[4]) && $m[4] !== '') {
                    $value = $m[4];
                }
                
                // 解析属性值中的占位符
                $valueNodes = $this->parseAttributeValue($value, $line);
                
                $attrs[] = NodePool::attrNode($line, $name, $valueNodes, $value);
            }
        }

        return $attrs;
    }

    /**
     * 解析属性值中的占位符
     * 
     * @return string|array<Node> 纯字符串或节点数组
     */
    private function parseAttributeValue(string $value, int $line): string|array
    {
        // 检查是否包含占位符
        if (!str_contains($value, '__PHP_')) {
            return $value;
        }

        $nodes = [];
        $offset = 0;
        
        while (preg_match('/__PHP_(\d+)__/', $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $m[0][1];
            $placeholder = $m[0][0];

            // 添加占位符前的文本
            if ($matchStart > $offset) {
                $text = substr($value, $offset, $matchStart - $offset);
                $nodes[] = NodePool::textNode($line, $text);
            }

            // 添加占位符节点
            $expression = '';
            $originalCode = '';
            $isEcho = true;

            if ($this->phpExtractor !== null) {
                $info = $this->phpExtractor->getPlaceholderInfo($placeholder);
                if ($info !== null) {
                    $expression = $info['expression'];
                    $originalCode = $info['code'];
                    $isEcho = $info['isEcho'];
                }
            }

            $nodes[] = NodePool::phpPlaceholder($line, $placeholder, $expression, $originalCode, $isEcho);
            $offset = $matchStart + strlen($placeholder);
        }

        // 添加剩余文本
        if ($offset < strlen($value)) {
            $nodes[] = NodePool::textNode($line, substr($value, $offset));
        }

        // 如果只有一个文本节点，返回字符串
        if (count($nodes) === 1 && $nodes[0] instanceof TextNode) {
            return $nodes[0]->value;
        }

        return $nodes;
    }
}
