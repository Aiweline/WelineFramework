<?php

declare(strict_types=1);

/**
 * `<string>task.name|25</string>`：旧链按 `|` 拆变量与长度，超过长度截断并加 `...`。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Ir\Stmt;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class StringTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'string',
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerString($node, $context)],
        );
    }

    private static function lowerString(TagNode $node, mixed $context): Stmt
    {
        $content = $context instanceof LowerContext ? $context->children($node) : '';
        $parts = \explode('|', $content, 2);
        $path = \trim((string)\array_shift($parts));
        $length = (int)\array_shift($parts);
        $template = $context instanceof LowerContext ? $context->template() : null;
        $expression = $template !== null
            ? $template->getTaglib()->varParser($path)
            : '$' . $path;

        $code = '<?php if(!empty(' . $expression . ')&&' . $length . '>0 && strlen(' . $expression . ')>' . $length . '){
                                    echo mb_substr(' . $expression . ',0,' . $length . ',\'UTF8\').\'...\';
                                }else{
                                echo ' . $expression . ';
                                }?>';

        return new RawPhpStmt($code, $node->span());
    }
}
