<?php

declare(strict_types=1);

/**
 * 控制流标签：`if` / `elseif` / `else` / `foreach`（P2 组）。
 *
 * 旧编译器对这四个标签的特殊之处：它们**不走 getTags() 回调**，而是被
 * `CodeGenerator::generateBuiltinTag()` 硬编码生成（优先级高于标签定义，见计划书 §2.3 D1）。
 * 因此本类的产物必须对齐 `CodeGenerator` 而不是 `Taglib`：
 *
 * - `if`      → `<?php if(COND): ?>CHILDREN<?php endif; ?>`（自闭合只吐前半段）
 * - `elseif`  → `<?php elseif(COND): ?>`
 * - `else`    → `<?php else: ?>`
 * - `foreach` → `<?php foreach(EXPR): ?>CHILDREN<?php endforeach; ?>`
 *
 * 条件/集合的取值顺序、`parseVarExpression` 的变量路径语义全部按旧实现复刻；
 * `ExpressionParser` 与 `CodeGenerator` 的对齐由 `ControlFlowTagTest` 用真实
 * `Taglib::compile()` 输出逐字节钉住。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Ir\Stmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\Node;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class ControlFlowTag
{
    private const OPEN = '<?php ';
    private const CLOSE = ' ?>';

    /**
     * @return list<TagDescriptor>
     */
    public static function descriptors(ExpressionParser $parser = new ExpressionParser()): array
    {
        return [
            self::ifDescriptor($parser),
            self::elseifDescriptor($parser),
            self::elseDescriptor(),
            self::foreachDescriptor(),
        ];
    }

    private static function ifDescriptor(ExpressionParser $parser): TagDescriptor
    {
        return new TagDescriptor(
            name: 'if',
            attributes: ['condition' => AttributeSpec::expr(), 'value' => AttributeSpec::expr()],
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static function (TagNode $node, mixed $context) use ($parser): array {
                $condition = self::conditionOf($node, $parser);
                $children = $context instanceof LowerContext ? $context->children($node) : '';
                $open = '<?php if(' . $condition . '): ?>';

                return [new RawPhpStmt($node->isSelfClosing() ? $open : $open . $children . '<?php endif; ?>', $node->span())];
            },
            inlineLower: static fn (InlineNode $node, mixed $context): array => [self::lowerInlineIf($node, $context)],
        );
    }

    /**
     * `@if{cond=>value|cond2=>value2|fallback}`：旧链走 `varParser()`，
     * 与元素 `if` 的 `parseVarExpression()` 产物不同，必须分开。
     */
    private static function lowerInlineIf(InlineNode $node, mixed $context): RawPhpStmt
    {
        $template = $context instanceof LowerContext ? $context->template() : null;
        $taglib = $template?->getTaglib();
        $items = \explode('|', $node->bodyRaw());
        $itemCount = \count($items);
        $code = '<?php ';
        $first = true;
        foreach ($items as $item) {
            $pair = \explode('=>', $item, 2);
            if (\count($pair) === 1) {
                $code .= ' else: echo ' . $pair[0] . ';';
                continue;
            }
            $condition = $taglib !== null
                ? $taglib->varParser($pair[0])
                : (new ExpressionParser())->parse($pair[0]);
            $code .= ($first ? 'if(' : ' elseif(') . $condition . '):echo ' . $pair[1] . ';';
            $first = false;
        }
        $code .= $itemCount === 1 ? 'endif;?>' : ' endif;?>';

        return new RawPhpStmt($code, $node->span());
    }

    private static function elseifDescriptor(ExpressionParser $parser): TagDescriptor
    {
        return new TagDescriptor(
            name: 'elseif',
            attributes: ['condition' => AttributeSpec::expr(), 'value' => AttributeSpec::expr()],
            paired: false,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static function (TagNode $node) use ($parser): array {
                return [new RawPhpStmt('<?php elseif(' . self::conditionOf($node, $parser) . '): ?>', $node->span())];
            },
        );
    }

    private static function elseDescriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'else',
            paired: false,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static fn (TagNode $node): array => [new RawPhpStmt('<?php else: ?>', $node->span())],
        );
    }

    private static function foreachDescriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'foreach',
            attributes: [
                'items' => AttributeSpec::expr(),
                'as' => AttributeSpec::any(),
                'key' => AttributeSpec::any(),
                'name' => AttributeSpec::any(),
                'item' => AttributeSpec::any(),
                'value' => AttributeSpec::expr(),
            ],
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static function (TagNode $node, mixed $context): array {
                $expression = self::foreachExpression($node);
                $children = $context instanceof LowerContext ? $context->children($node) : '';
                $open = '<?php foreach(' . $expression . '): ?>';

                return [new RawPhpStmt($node->isSelfClosing() ? $open : $open . $children . '<?php endforeach; ?>', $node->span())];
            },
        );
    }

    /**
     * 条件取值顺序与 `generateIfTag()` / `generateElseifTag()` 一致：
     * `condition` → `value` → 子内容原文 → `true`。
     */
    private static function conditionOf(TagNode $node, ExpressionParser $parser): string
    {
        $attributes = $node->attributes();
        $condition = (string)($attributes['condition'] ?? $attributes['value'] ?? '');
        if ($condition === '') {
            $condition = \trim(self::rawChildren($node));
        }
        if ($condition === '') {
            return 'true';
        }

        return $parser->parse($condition);
    }

    /**
     * 集合表达式与 `generateForeachTag()` 一致：items/as/key → name/item/key → value → 子内容。
     */
    private static function foreachExpression(TagNode $node): string
    {
        $attributes = $node->attributes();
        $items = $attributes['items'] ?? null;
        $as = $attributes['as'] ?? null;
        $key = $attributes['key'] ?? null;

        if ($items !== null && $as !== null) {
            $itemsVar = \str_starts_with((string)$items, '$') ? (string)$items : '$' . $items;
            $asVar = \str_starts_with((string)$as, '$') ? (string)$as : '$' . $as;
            if ($key !== null) {
                $keyVar = \str_starts_with((string)$key, '$') ? (string)$key : '$' . $key;

                return "{$itemsVar} as {$keyVar} => {$asVar}";
            }

            return "{$itemsVar} as {$asVar}";
        }

        $name = $attributes['name'] ?? null;
        if ($name !== null) {
            $item = (string)($attributes['item'] ?? 'v');
            $keyExpr = $attributes['key'] ?? null;
            $keyExpr = $keyExpr !== null ? '$' . \ltrim((string)$keyExpr, '$') : null;

            return $keyExpr !== null
                ? '$' . \ltrim((string)$name, '$') . " as {$keyExpr} => \${$item}"
                : '$' . \ltrim((string)$name, '$') . " as \${$item}";
        }

        if (isset($attributes['value'])) {
            return (string)$attributes['value'];
        }

        $raw = \trim(self::rawChildren($node));

        return $raw !== '' ? $raw : '[] as $item';
    }

    private static function rawChildren(TagNode $node): string
    {
        $raw = '';
        foreach ($node->children() as $child) {
            $raw .= $child instanceof Node ? $child->raw() : '';
        }

        return $raw;
    }
}
