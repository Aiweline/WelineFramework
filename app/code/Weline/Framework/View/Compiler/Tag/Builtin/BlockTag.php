<?php

declare(strict_types=1);

/**
 * `block` 标签（B2，语料 39 处：37 元素 + 2 内联）。
 *
 * 旧编译器对它的产物有三条硬约束，必须逐字节复刻：
 *
 * 1. **成对形态**（`<block ...>...</block>`）走 `tag` 分支：
 *    `<?php echo framework_view_process_block([...]);?>`
 *    `$data = explode('|', 子内容)` 后与属性 `array_merge`，所以空内容时会多出 `'0' => ''`。
 * 2. **自闭合形态**（`<block .../>`）多一个 `$vars` 实参：
 *    `<?php echo framework_view_process_block([...],$vars=['model'=>&$model,]);?>`
 *    这里只输出变量名（`&$model`），编译期不需要变量值；`vars` 语义是**按引用**把
 *    当前作用域变量传进 block，不能在 Lower 阶段求值。
 * 3. **属性里的 `@lang(...)`**（I18n 的 inline-edit 场景）会被旧 `expandInlineTags()`
 *    在属性上下文里改成 `InlinePhraseMarker::emit(词, true)`；元素标签（如 `<lang>`）
 *    在属性值里**不展开**，原样当字符串。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Ir\Stmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;
use Weline\Framework\View\Exception\TemplateException;

final class BlockTag
{
    private const OPEN_PHP = '<?php echo framework_view_process_block(';
    private const CLOSE_PHP = ');?>';

    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'block',
            attributes: [
                'class' => AttributeSpec::any(),
                'template' => AttributeSpec::any(),
                'cache' => AttributeSpec::any(),
                'vars' => AttributeSpec::any(),
            ],
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerElement($node, $context)],
            inlineLower: static fn (InlineNode $node): array => [self::lowerInline($node)],
        );
    }

    private static function lowerElement(TagNode $node, mixed $context): Stmt
    {
        $attributes = self::compileAttributeValues($node->attributes(), $context);

        if ($node->isSelfClosing()) {
            return self::selfClosingStatement($node, $attributes);
        }

        $children = $context instanceof LowerContext ? $context->children($node) : '';
        // 旧 AST 的既成产物：成对 `<block ...></block>` 若属性里有内联标签（展开成 PHP），
        // 空子内容会被判成自闭合分支（多一个 `$vars`，少 `'0' => ''`）。
        // 有子内容时仍是成对分支（见 BlockTagTest 的 synthetic 形状）。
        if ($children === '' && self::hasPhpAttribute($attributes)) {
            return self::selfClosingStatement($node, $attributes);
        }

        $data = \array_merge(\explode('|', $children), $attributes);

        return new RawPhpStmt(
            self::OPEN_PHP . self::exportAttributes($data) . self::CLOSE_PHP,
            $node->span(),
        );
    }

    /**
     * 旧 tag-self-close 分支（真实自闭合，或成对但空内容 + 属性内联标签）。
     *
     * @param array<string, string|true> $attributes
     */
    private static function selfClosingStatement(TagNode $node, array $attributes): RawPhpStmt
    {
        // 旧分支允许把 value 当 class 的别名（内联形态遗留）。
        if ((!isset($attributes['class']) || !$attributes['class'])
            && isset($attributes['value']) && $attributes['value']) {
            $attributes['class'] = $attributes['value'];
            unset($attributes['value']);
        }
        if (!isset($attributes['class']) || !$attributes['class']) {
            throw new TemplateException(
                \sprintf(
                    'block 标签语法错误：未指定 block 类。示例：%s',
                    \htmlentities("<block class='Vendor\\Module\\Block\\Demo' template='Vendor_Module::block/demo.phtml'/>"),
                ),
            );
        }

        $varsString = '[';
        if (isset($attributes['vars'])) {
            foreach (\explode(',', (string)$attributes['vars']) as $var) {
                $varName = \trim($var);
                $varsString .= "'{$varName}'=>&\${$varName},";
            }
        }
        $varsString .= ']';

        return new RawPhpStmt(
            self::OPEN_PHP . self::exportAttributes($attributes) . ',$vars=' . $varsString . self::CLOSE_PHP,
            $node->span(),
        );
    }

    /**
     * 属性值是否已经含 PHP（内联标签展开的结果）。
     *
     * 用来复刻旧 AST 的分支判定：只有 PHP 占位符（而非纯文本/烘焙结果）才会
     * 让空内容的成对 block 走自闭合分支。
     *
     * @param array<string, string|true> $attributes
     */
    private static function hasPhpAttribute(array $attributes): bool
    {
        foreach ($attributes as $value) {
            if (\is_string($value) && \str_contains($value, '<?')) {
                return true;
            }
        }

        return false;
    }

    private static function lowerInline(InlineNode $node): Stmt
    {
        $data = \explode('|', $node->bodyRaw());
        if (!isset($data[0]) || $data[0] === '') {
            throw new TemplateException(
                \sprintf('@block 标签语法错误：未指定 block 类。原始片段：%s', \htmlentities($node->raw())),
            );
        }

        return new RawPhpStmt(
            self::OPEN_PHP . self::exportAttributes($data) . self::CLOSE_PHP,
            $node->span(),
        );
    }

    /**
     * 属性值里的内联标签展开（旧 `expandInlineTags()` 在属性上下文的效果）。
     *
     * 只展开 `@lang(...)` / `@lang{...}` 这类**整值**内联标签：
     * - 旧实现仅在 token 被成对引号包住时切到 `forAttribute=true`；
     * - 元素标签（`<lang>...</lang>`）与混合文本保持原样。
     *
     * @param array<string, string|true> $attributes
     * @return array<string, string|true>
     */
    private static function compileAttributeValues(array $attributes, mixed $context): array
    {
        if (!$context instanceof LowerContext) {
            return $attributes;
        }

        foreach ($attributes as $key => $value) {
            if (!\is_string($value) || !\str_contains($value, '@')) {
                continue;
            }
            $trimmed = \trim($value);
            $isLangAttribute = \preg_match('/^@(?:w:)?(?:lang|trans)\s*[({].*[)}]$/s', $trimmed) === 1;

            try {
                $expanded = $context->inline($value);
            } catch (\Throwable) {
                // 与旧 expandInlineTags 一致：单个内联 token 失败就保留原文，不阻断编译。
                continue;
            }

            if ($isLangAttribute) {
                $expanded = (string)\preg_replace(
                    '/InlinePhraseMarker::emit\((.+),\s*false\)/s',
                    'InlinePhraseMarker::emit($1, true)',
                    $expanded,
                    1,
                );
                if (!\str_contains($expanded, 'InlinePhraseMarker::emit')
                    && \preg_match('/^<\?=\s*__\((.+)\)\s*\?>$/s', \trim($expanded), $matches) === 1) {
                    $expanded = '<?=\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::emit('
                        . $matches[1] . ', true) ?>';
                }
            }

            $attributes[$key] = $expanded;
        }

        return $attributes;
    }

    /**
     * 与旧 `exportBlockProcessAttributes()` 逐字节一致（键转字符串、值按 PHP 字面量）。
     *
     * @param array<array-key, mixed> $attributes
     */
    private static function exportAttributes(array $attributes): string
    {
        if ($attributes === []) {
            return '[]';
        }

        $pairs = [];
        foreach ($attributes as $key => $value) {
            $pairs[] = \var_export((string)$key, true) . ' => ' . self::exportValue($value);
        }

        return '[' . \implode(', ', $pairs) . ']';
    }

    private static function exportValue(mixed $value): string
    {
        if (!\is_string($value)) {
            return \w_var_export($value, true);
        }

        $trimmed = \trim($value);
        // 属性内联标签展开后是「短回显 + 表达式 + 闭标签」；旧实现把它落成裸表达式
        // `(expr)`，而不是带引号的字符串，否则 block 属性拿到的是 PHP 源码文本。
        if (\preg_match('/^<\?=\s*(.*?)\s*\?>$/s', $trimmed, $matches) === 1) {
            return '(' . $matches[1] . ')';
        }
        if (\preg_match('/^<\?(?:php)?\s+echo\s+(.*?);\s*\?>$/s', $trimmed, $matches) === 1) {
            return '(' . \rtrim(\trim($matches[1]), ';') . ')';
        }

        return \var_export($value, true);
    }
}
