<?php

declare(strict_types=1);

/**
 * 标签描述符 —— v3 的「唯一定义源」。
 *
 * 一个标签的元数据（名字/别名/属性/配对形态/阶段）与行为（`lower`）都在这一个对象里，
 * 不允许再散落到 `getTags()` 闭包、`CodeGenerator` 硬编码、`TagRegistry` 元数据三处
 * （现状见外部计划书 §2.3 D1）。
 *
 * `$lower` 的签名：`fn(TagNode $node, LowerContext $context): list<Stmt>`。
 * 它返回**语句树**而不是字符串——这是 v3 与旧实现最根本的区别。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

use Closure;
use Weline\Framework\View\Compiler\Parser\TagNode;

final class TagDescriptor
{
    public const STAGE_COMPILE = 'compile';
    public const STAGE_RUNTIME = 'runtime';
    public const STAGE_AUTO = 'auto';

    /**
     * @param list<string> $aliases
     * @param array<string, AttributeSpec> $attributes
     * @param Closure(TagNode, mixed): list<\Weline\Framework\View\Compiler\Ir\Stmt>|null $lower
     * @param Closure(\Weline\Framework\View\Compiler\Parser\InlineNode, mixed): list<\Weline\Framework\View\Compiler\Ir\Stmt>|null $inlineLower
     */
    public function __construct(
        public readonly string $name,
        public readonly array $aliases = [],
        public readonly array $attributes = [],
        public readonly bool $paired = false,
        public readonly bool $selfClosing = true,
        public readonly string $stage = self::STAGE_AUTO,
        public readonly bool $ownsChildren = false,
        public readonly ?Closure $lower = null,
        public readonly bool $elementForm = true,
        public readonly bool $inlineForm = true,
        public readonly ?Closure $inlineLower = null,
        /** 只允许 `w:` 前缀形态（原生 HTML 同名标签不能被当成模板标签，如 <form>） */
        public readonly bool $wOnly = false,
    ) {
    }

    /**
     * 是否带某个别名或本名（大小写不敏感）。
     */
    public function answersTo(string $name): bool
    {
        if (\strcasecmp($this->name, $name) === 0) {
            return true;
        }
        foreach ($this->aliases as $alias) {
            if (\strcasecmp($alias, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allNames(): array
    {
        return \array_values(\array_unique([$this->name, ...$this->aliases]));
    }

    public function canLower(): bool
    {
        return $this->lower instanceof Closure;
    }

    public function canLowerInline(): bool
    {
        return $this->inlineLower instanceof Closure;
    }
}
