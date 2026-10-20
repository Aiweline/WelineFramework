<?php

declare(strict_types=1);

/**
 * `hook` 标签（B2）。
 *
 * 三条真实路径：
 * 1. **无实现 / hook 不存在** → 空串；带 `<else/>` 时输出 fallback 内容。
 *    （实测旧编译器 `<div><hook>footer</hook></div>` → `<div></div>`）
 * 2. **有实现（PROD）** → 复用 `HookCompiledPhpInliner::tryEmit()`，与旧编译器
 *    同一份烘焙逻辑：输出 baked-hook 标记 + 对贡献者编译产物的 include。
 * 3. **有实现但 inliner 返回 null**（DEV / `runtime` 属性 / 编辑器预览）→
 *    与旧 `emitCompiledHookPhp()` 一致退回运行期 `getHook()` 短回显；
 *    带 `<else/>` 时退回旧运行期 fallback 代码。
 *
 * 关键修复：`CompiledExtensionRegistry` 的真实命名空间是
 * `Weline\Framework\Compilation\...`。写错时异常被吞，有实现的 hook 会被误判为
 * 「无实现」并静默输出空串——checkout/cart/backend layout 的 baked hook 曾因此丢失。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\Compilation\CompiledExtensionRegistry;
use Weline\Framework\Hook\Config\HookReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;
use Weline\Framework\View\HookCompiledPhpInliner;

final class HookTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'hook',
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerElement($node, $context)],
        );
    }

    private static function lowerElement(TagNode $node, mixed $context): RawPhpStmt
    {
        $attributes = $node->attributes();
        [$elseIndex, $elseContent] = self::splitElse($node, $context);
        if ($elseContent !== null && self::elseSharesNameLine($node, $elseIndex)) {
            // 旧链只在 `<else/>` 与 hook 名同一行时吃掉 fallback 前导空白；
            // hook 名跨行（如 products 布局）时保留原缩进。
            $elseContent = \ltrim($elseContent);
        }
        $name = self::resolveName($node, $context, $elseIndex);

        if ($name === '' || !self::hasImplementations($name)) {
            // 自闭合/name 属性形态：旧链走单标签分支，即使没有实现文件也保留运行期
            // getHook（Login 的 providers 等），不能静默空串。
            if ($name !== '' && $node->isSelfClosing()) {
                return new RawPhpStmt('<?=$this->getHook(\'' . \addslashes($name) . '\')?>', $node->span());
            }

            // 成对无实现：返回 else 内容（没有 else 就是空串）。
            return new RawPhpStmt($elseContent ?? '', $node->span());
        }

        try {
            $baked = HookCompiledPhpInliner::tryEmit($name, $attributes);
        } catch (\Throwable) {
            $baked = null;
        }
        if ($baked !== null) {
            // 有实现且可烘焙：与旧 PROD 产物逐字节一致（含 baked-hook 注释与 include 顺序）。
            return new RawPhpStmt($baked, $node->span());
        }

        if ($elseContent !== null) {
            // 旧运行期 fallback：HookRenderResult 决定是否用 else 内容。
            $fallback = '<?php $__w_hr=method_exists($this,\'getHookResult\')?$this->getHookResult('
                . \var_export($name, true)
                . ', false, true):null; if(!$__w_hr instanceof \Weline\Framework\Hook\HookRenderResult || $__w_hr->shouldUseFallback()): ?>'
                . $elseContent
                . '<?php else: echo $__w_hr->html; endif; ?>';

            return new RawPhpStmt($fallback, $node->span());
        }

        // 旧 `emitCompiledHookPhp()` 在 inliner 不可用时退回运行期 getHook。
        return new RawPhpStmt('<?=$this->getHook(\'' . \addslashes($name) . '\')?>', $node->span());
    }

    /**
     * 在子节点里找 `<else/>`：之前是 hook 名，之后是 fallback。
     *
     * @return array{0:int|null,1:string|null} [else 子节点下标, fallback 已编译内容]
     */
    private static function splitElse(TagNode $node, mixed $context): array
    {
        if (!$context instanceof LowerContext) {
            return [null, null];
        }

        foreach ($node->children() as $index => $child) {
            if (!$child instanceof TagNode) {
                continue;
            }
            $childName = \strtolower($child->name());
            if ($childName === 'else' || $childName === 'w:else') {
                return [$index, $context->childrenRange($node, $index + 1)];
            }
        }

        return [null, null];
    }

    private static function elseSharesNameLine(TagNode $node, ?int $elseIndex): bool
    {
        if ($elseIndex === null) {
            return false;
        }
        $prefix = '';
        foreach (\array_slice($node->children(), 0, $elseIndex) as $child) {
            $prefix .= $child->raw();
        }

        return !\str_contains($prefix, "\n");
    }

    private static function resolveName(TagNode $node, mixed $context, ?int $elseIndex): string
    {
        $attributes = $node->attributes();
        $name = \trim((string)($attributes['name'] ?? ''));
        if ($name === '' && $context instanceof LowerContext) {
            $name = $elseIndex === null
                ? $context->children($node)
                : $context->childrenRange($node, 0, $elseIndex);
        }
        if ($name === '') {
            $name = \trim($node->raw());
            $name = (string)\preg_replace('/^<hook[^>]*>|<\/hook>$/i', '', $name);
        }

        return \preg_replace('/\s+/', '', \trim($name)) ?? '';
    }

    private static function hasImplementations(string $name): bool
    {
        try {
            if (!CompiledExtensionRegistry::hookExists($name)) {
                return false;
            }
        } catch (\Throwable) {
            // 注册表不可用：无法确认，按「没有实现」走空串（与迁移期边界一致）。
            return false;
        }

        try {
            $reader = ObjectManager::make(HookReader::class);
            $reader->setPath($name);

            return $reader->getFileList() !== [];
        } catch (\Throwable) {
            // hook 已确认存在但读不到实现文件：按「有实现」处理（走 inliner / getHook）。
            return true;
        }
    }
}
