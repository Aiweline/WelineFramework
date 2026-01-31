<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 节点对象池（Flyweight 模式）
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * 节点对象池
 * 
 * 使用 Flyweight 模式复用相同内容的节点对象，减少 GC 压力
 * 
 * @example
 * ```php
 * // 相同内容的 TextNode 会复用同一对象
 * $node1 = NodePool::textNode(1, "hello");
 * $node2 = NodePool::textNode(2, "hello");
 * // $node1 === $node2 (如果行号不参与 key)
 * ```
 */
final class NodePool
{
    /**
     * TextNode 缓存池
     * @var array<string, TextNode>
     */
    private static array $textNodes = [];

    /**
     * PhpPlaceholder 缓存池
     * @var array<string, PhpPlaceholder>
     */
    private static array $phpNodes = [];

    /**
     * 最大缓存条目数
     */
    private const MAX_TEXT_NODES = 5000;
    private const MAX_PHP_NODES = 2000;

    /**
     * 禁止实例化
     */
    private function __construct() {}

    /**
     * 获取或创建 TextNode
     * 
     * @param int $line 行号
     * @param string $value 文本内容
     * @return TextNode
     */
    public static function textNode(int $line, string $value): TextNode
    {
        // 先对字符串去重
        $value = StringInterner::intern($value);
        
        // 使用值作为 key（忽略行号以提高复用率）
        $key = $value;
        
        if (isset(self::$textNodes[$key])) {
            return self::$textNodes[$key];
        }

        // 防止缓存溢出
        if (count(self::$textNodes) >= self::MAX_TEXT_NODES) {
            self::$textNodes = array_slice(self::$textNodes, self::MAX_TEXT_NODES / 2, null, true);
        }

        $node = new TextNode($line, $value);
        self::$textNodes[$key] = $node;
        return $node;
    }

    /**
     * 获取或创建 PhpPlaceholder
     * 
     * @param int $line 行号
     * @param string $placeholder 占位符
     * @param string $expression PHP 表达式
     * @param string $originalCode 原始代码
     * @param bool $isEcho 是否为 echo 格式
     * @return PhpPlaceholder
     */
    public static function phpPlaceholder(
        int $line,
        string $placeholder,
        string $expression,
        string $originalCode = '',
        bool $isEcho = true
    ): PhpPlaceholder {
        // 占位符是唯一的，用它作为 key
        $key = $placeholder;
        
        if (isset(self::$phpNodes[$key])) {
            return self::$phpNodes[$key];
        }

        // 防止缓存溢出
        if (count(self::$phpNodes) >= self::MAX_PHP_NODES) {
            self::$phpNodes = array_slice(self::$phpNodes, self::MAX_PHP_NODES / 2, null, true);
        }

        $node = new PhpPlaceholder($line, $placeholder, $expression, $originalCode, $isEcho);
        self::$phpNodes[$key] = $node;
        return $node;
    }

    /**
     * 创建 AttrNode（不缓存，因为属性值可能很复杂）
     */
    public static function attrNode(int $line, string $name, string|array $value, string $rawValue = ''): AttrNode
    {
        $name = StringInterner::intern($name);
        if (is_string($value)) {
            $value = StringInterner::intern($value);
        }
        return new AttrNode($line, $name, $value, $rawValue);
    }

    /**
     * 创建 TagNode（不缓存，因为结构可能很复杂）
     */
    public static function tagNode(
        int $line,
        string $name,
        array $attributes = [],
        array $children = [],
        string $rawContent = '',
        bool $selfClosing = false,
        string $stage = TagNode::STAGE_COMPILE
    ): TagNode {
        $name = StringInterner::intern($name);
        return new TagNode($line, $name, $attributes, $children, $rawContent, $selfClosing, $stage);
    }

    /**
     * 创建 ProgramNode
     */
    public static function programNode(int $line, array $children = [], string $fileName = ''): ProgramNode
    {
        return new ProgramNode($line, $children, $fileName);
    }

    /**
     * 重置所有缓存池
     */
    public static function reset(): void
    {
        self::$textNodes = [];
        self::$phpNodes = [];
        StringInterner::reset();
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array{textNodes: int, phpNodes: int, strings: array}
     */
    public static function stats(): array
    {
        return [
            'textNodes' => count(self::$textNodes),
            'phpNodes' => count(self::$phpNodes),
            'strings' => StringInterner::stats(),
        ];
    }
}
