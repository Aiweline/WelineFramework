<?php

declare(strict_types=1);

/**
 * `<php>code</php>`：旧链原样包成 `<?php code ?>`（前后各一个空格）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Ir\Stmt;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class PhpTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'php',
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: false,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerPhp($node, $context)],
        );
    }

    private static function lowerPhp(TagNode $node, mixed $context): Stmt
    {
        $content = $context instanceof LowerContext ? $context->children($node) : '';

        return new RawPhpStmt('<?php ' . $content . ' ?>', $node->span());
    }
}
