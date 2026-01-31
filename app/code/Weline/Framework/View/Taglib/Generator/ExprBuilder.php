<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 表达式构建器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Generator
 */

namespace Weline\Framework\View\Taglib\Generator;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    TextNode,
    PhpPlaceholder,
    AttrNode
};

/**
 * 表达式构建器
 * 
 * 构建 PHP 表达式字符串
 */
final class ExprBuilder
{
    /**
     * 从节点数组构建字符串拼接表达式
     * 
     * @param array<Node> $nodes
     * @return string PHP 表达式
     */
    public static function buildFromNodes(array $nodes): string
    {
        if (empty($nodes)) {
            return "''";
        }

        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                // 文本节点转义为字符串
                $parts[] = var_export($node->value, true);
            } elseif ($node instanceof PhpPlaceholder) {
                // PHP 占位符转换为表达式
                $expr = $node->getRuntimeExpression();
                $parts[] = "({$expr})";
            } else {
                // 其他节点跳过
                continue;
            }
        }

        if (empty($parts)) {
            return "''";
        }

        // 单个部分直接返回
        if (count($parts) === 1) {
            return $parts[0];
        }

        // 多个部分用 . 连接
        return implode(' . ', $parts);
    }

    /**
     * 从属性节点构建表达式
     */
    public static function buildFromAttr(AttrNode $attr): string
    {
        if (is_string($attr->value)) {
            return var_export($attr->value, true);
        }

        return self::buildFromNodes($attr->value);
    }

    /**
     * 构建属性数组表达式
     * 
     * @param array<AttrNode> $attrs
     * @return string PHP 数组表达式
     */
    public static function buildAttrArray(array $attrs): string
    {
        if (empty($attrs)) {
            return '[]';
        }

        $pairs = [];

        foreach ($attrs as $attr) {
            $key = var_export($attr->name, true);
            $value = self::buildFromAttr($attr);
            $pairs[] = "{$key} => {$value}";
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * 构建安全的 echo 表达式
     */
    public static function buildEchoExpr(string $expr, bool $escape = true): string
    {
        if ($escape) {
            return "htmlspecialchars((string)({$expr}), ENT_QUOTES, 'UTF-8')";
        }
        return $expr;
    }

    /**
     * 构建条件表达式
     */
    public static function buildConditional(string $condition, string $trueExpr, string $falseExpr = "''"): string
    {
        return "({$condition}) ? ({$trueExpr}) : ({$falseExpr})";
    }

    /**
     * 将字符串包装为 PHP echo 语句
     */
    public static function wrapEcho(string $expr): string
    {
        return '<' . '?= ' . $expr . ' ?' . '>';
    }

    /**
     * 将字符串包装为 PHP 语句
     */
    public static function wrapPhp(string $code): string
    {
        return '<' . '?php ' . $code . ' ?' . '>';
    }
}
