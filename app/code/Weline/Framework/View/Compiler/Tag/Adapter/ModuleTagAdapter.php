<?php

declare(strict_types=1);

/**
 * 模块标签适配器：把 `TaglibInterface` 桥成 `TagDescriptor`。
 *
 * 目标（C3）：**86 个模块标签一行不改**。它们的声明是静态方法
 * （`name` / `tag` / `attr` / `tag_start` / `tag_end` / 两个 self-close 开关 / `callback`），
 * 适配器只做「声明 → 描述符」的翻译，不复制任何业务逻辑。
 *
 * 形态判定来自声明本身，不是猜的：
 * - `tag()` 为真：支持成对元素形态；
 * - `tag_start()` / `tag_end()`：支持单边元素形态；
 * - `tag_self_close()` / `tag_self_close_with_attrs()`：支持自闭合；
 * - 四项全假（如 `Weline_Meta`）：只支持内联形态 `@name{...}`，
 *   绝不能把 HTML `<meta>` 当成它（这是 P1 的真实踩坑）。
 *
 * `lower` 走 `ModuleTagBridge`：旧 `callback()` 收到的 `$tag_key / $config / $tag_data`
 * 三个参数由桥逐字还原，返回值原样包成 `RawPhpStmt`。
 * 注意：桥只保证「与旧编译器同协议」，不保证「语义已在新管线验证」——
 * 真正接管编译入口要等影子比对（S1–S4）通过。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Adapter;

use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class ModuleTagAdapter
{
    /**
     * @param class-string<TaglibInterface> $taglibClass
     *
     * @throws \InvalidArgumentException 不是合法的标签实现时
     */
    public function adapt(string $taglibClass): TagDescriptor
    {
        if (!\is_a($taglibClass, TaglibInterface::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                '%s 未实现 %s',
                $taglibClass,
                TaglibInterface::class,
            ));
        }

        $name = \trim((string)$taglibClass::name());
        if ($name === '') {
            throw new \InvalidArgumentException($taglibClass . ' 的 name() 为空');
        }

        $paired = (bool)$taglibClass::tag();
        $tagStart = (bool)$taglibClass::tag_start();
        $tagEnd = (bool)$taglibClass::tag_end();
        $selfClosing = (bool)$taglibClass::tag_self_close()
            || (bool)$taglibClass::tag_self_close_with_attrs();
        $elementForm = $paired || $tagStart || $tagEnd || $selfClosing;

        return new TagDescriptor(
            name: $name,
            aliases: [],
            attributes: $this->attributes($taglibClass),
            paired: $paired,
            selfClosing: $selfClosing,
            // 静态属性走编译期 callback 桥；动态属性按旧 StageResolver 走 runtime 下发。
            stage: TagDescriptor::STAGE_AUTO,
            ownsChildren: false,
            lower: static function (
                \Weline\Framework\View\Compiler\Parser\TagNode $node,
                mixed $context
            ) use ($taglibClass): array {
                $children = $context instanceof \Weline\Framework\View\Compiler\Ir\LowerContext
                    ? $context->children($node)
                    : '';

                return [(new ModuleTagBridge($taglibClass))->lower($node, $children)];
            },
            elementForm: $elementForm,
            // 旧编译器对注册过的标签都接受内联写法，这里保持一致；
            // 元素形态是否成立由上面的声明决定（Meta 这类只有内联）。
            inlineForm: true,
        );
    }

    /**
     * @param class-string<TaglibInterface> $taglibClass
     * @return array<string, AttributeSpec>
     */
    private function attributes(string $taglibClass): array
    {
        $attributes = [];
        foreach ($taglibClass::attr() as $attribute => $required) {
            $attribute = \trim((string)$attribute);
            if ($attribute === '') {
                continue;
            }
            // 旧声明只有「名字 => 是否必填」，没有类型信息；
            // 类型推断留给 Resolve 阶段按用法判断，这里不臆造。
            $attributes[$attribute] = AttributeSpec::any((bool)$required);
        }

        return $attributes;
    }
}
