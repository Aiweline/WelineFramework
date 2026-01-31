<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST 基础节点抽象类
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * AST 基础节点
 * 
 * 使用 PHP 8.4+ Property Hooks 实现计算属性
 */
abstract class Node
{
    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param int $column 源码列号
     */
    public function __construct(
        public readonly int $line,
        public readonly int $column = 0,
    ) {}

    /**
     * 是否为动态节点（包含 PHP 代码）
     * 
     * Property Hook: 计算属性，延迟求值
     */
    public bool $isDynamic {
        get => false;
    }

    /**
     * 获取节点类型名称
     */
    public string $typeName {
        get => basename(str_replace('\\', '/', static::class));
    }

    /**
     * 转换为调试字符串
     */
    abstract public function toDebugString(): string;
}
