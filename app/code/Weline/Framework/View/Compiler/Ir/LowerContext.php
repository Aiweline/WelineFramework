<?php

declare(strict_types=1);

/**
 * Lower 阶段上下文。
 *
 * 描述符的 `lower()` 需要两样东西：节点本身，以及「子节点的产物」。
 * 旧协议把子节点编译成字符串再传给 `callback()`，新管线保持一致，
 * 但把「怎么拿到子节点产物」收口到这个上下文，避免描述符直接依赖 Emitter/Parser。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

use Closure;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Template;

final class LowerContext
{
    /**
     * @param Closure(TagNode): string $childrenEmitter
     * @param Closure(string): string|null $inlineCompiler 属性值等片段里的内联标签展开
     * @param Closure(TagNode, int, ?int): string|null $childrenRangeEmitter
     *        只编译子节点的一段（hook 的 `<else/>` 需要「名称部分 / fallback 部分」分开取）
     */
    public function __construct(
        private readonly Closure $childrenEmitter,
        private readonly ?Template $template = null,
        private readonly ?Closure $inlineCompiler = null,
        private readonly ?Closure $childrenRangeEmitter = null,
    ) {
    }

    /**
     * 只读模板实例：编译期取源（`fetchTagSource`）/烘焙（`tryBakeUrlFamily`）需要它。
     * 只允许读，不允许在 Lower 阶段改写模板状态。
     */
    public function template(): ?Template
    {
        return $this->template;
    }

    /**
     * 子节点已 Emit 的产物（旧协议里的 `$content`）。
     */
    public function children(TagNode $node): string
    {
        return ($this->childrenEmitter)($node);
    }

    /**
     * 编译一段「片段文本」里的内联标签（`@lang(...)` 等），元素标签保持不变。
     *
     * 用途：`block` 的属性值是先于标签回调展开的（旧 `expandInlineTags()`），
     * 但元素标签在属性值里不展开。未注入时原样返回，调用方自行判断。
     */
    public function inline(string $text): string
    {
        return $this->inlineCompiler === null ? $text : ($this->inlineCompiler)($text);
    }

    /**
     * 只编译子节点的一段（`$offset` 起、`$length` 个；null = 到末尾）。
     *
     * `hook` 用它在 `<else/>` 处切分：else 之前是 hook 名，之后是 fallback。
     */
    public function childrenRange(TagNode $node, int $offset, ?int $length = null): string
    {
        if ($this->childrenRangeEmitter === null) {
            return '';
        }

        return ($this->childrenRangeEmitter)($node, $offset, $length);
    }
}
