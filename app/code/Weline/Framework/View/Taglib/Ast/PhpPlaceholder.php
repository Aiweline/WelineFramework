<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AST PHP 占位符节点
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Ast
 */

namespace Weline\Framework\View\Taglib\Ast;

/**
 * PHP 占位符节点
 * 
 * 表示被提取的 PHP 代码块的占位符
 */
class PhpPlaceholder extends Node
{
    /**
     * 构造函数
     *
     * @param int $line 源码行号
     * @param string $placeholder 占位符标识（如 __PHP_001__）
     * @param string $expression PHP 表达式（不含 <?php ?> 包装）
     * @param string $originalCode 原始 PHP 代码（含 <?php ?> 包装）
     * @param bool $isEcho 是否为 <?= ?> 格式
     */
    public function __construct(
        int $line,
        public readonly string $placeholder,
        public readonly string $expression,
        public readonly string $originalCode = '',
        public readonly bool $isEcho = true,
    ) {
        parent::__construct($line);
    }

    /**
     * Property Hook: PHP 占位符始终动态
     */
    public bool $isDynamic {
        get => true;
    }

    /**
     * Property Hook: 是否为简单变量（如 $var）
     */
    public bool $isSimpleVariable {
        get => (bool)preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', trim($this->expression));
    }

    /**
     * 获取运行期表达式
     */
    public function getRuntimeExpression(): string
    {
        $expr = trim($this->expression);
        // 移除尾部分号
        $expr = rtrim($expr, ';');
        return $expr;
    }

    /**
     * @inheritDoc
     */
    public function toDebugString(): string
    {
        $preview = strlen($this->expression) > 40 
            ? substr($this->expression, 0, 40) . '...' 
            : $this->expression;
        return sprintf(
            'PhpPlaceholder[line=%d, echo=%s](%s => %s)',
            $this->line,
            $this->isEcho ? 'true' : 'false',
            $this->placeholder,
            $preview
        );
    }
}
