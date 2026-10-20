<?php

declare(strict_types=1);

/**
 * `<var>` 标签：输出一个模板变量。
 *
 * 旧编译器产物是字面量 `<?=$title ?>`（见 `VarTagTest` 对旧 `Taglib::compile()` 的比对），
 * 注意它**不做** `?? getData()` 兜底——这是与 `{{title}}` 的关键区别，不能“顺手统一”。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class VarTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'var',
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static function (TagNode $node, mixed $context): array {
                $content = \trim($context instanceof LowerContext ? $context->children($node) : '');
                // 旧编译器不会重复加 `$`：<var>$title</var> 与 <var>title</var> 产物相同。
                if (!\str_starts_with($content, '$')) {
                    $content = '$' . $content;
                }

                return [new RawPhpStmt('<?=' . $content . ' ?>', $node->span())];
            },
            inlineLower: static function (InlineNode $node): array {
                $expression = \trim($node->bodyRaw());
                // 旧 `@var(expr)` 走 `varParser()`：裸标识补 `$`，函数调用/`$var` 原样。
                if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expression) === 1) {
                    $expression = '$' . $expression;
                }

                return [new RawPhpStmt('<?=' . $expression . ' ?>', $node->span())];
            },
        );
    }
}
