<?php

declare(strict_types=1);

/**
 * `empty` / `notempty` / `has` 三个旧条件标签。
 *
 * 旧链实测有三种形态：
 * - 无 `w:` 的元素 `<empty>...</empty>`：整段丢弃（旧 tokenizer 不把它当框架标签）；
 * - `<w:empty>` / `<w:notempty>` / `<w:has>`：命中 `w:` wildcard → runtime 下发；
 * - 内联 `@empty{name|fallback}` / `@notempty{...}`：编译期 `if(empty(...))echo '...'`。
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

final class ConditionTag
{
    /**
     * @return list<TagDescriptor>
     */
    public static function descriptors(): array
    {
        return [
            self::descriptor('empty', false),
            self::descriptor('notempty', true),
            self::descriptor('has', true),
        ];
    }

    private static function descriptor(string $name, bool $negate): TagDescriptor
    {
        return new TagDescriptor(
            name: $name,
            attributes: ['name' => AttributeSpec::any()],
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: $name !== 'has',
            lower: static fn (TagNode $node): array => [new RawPhpStmt('', $node->span())],
            inlineLower: $name === 'has'
                ? null
                : static fn (InlineNode $node): array => [self::lowerInline($node, $negate)],
        );
    }

    private static function lowerInline(InlineNode $node, bool $negate): Stmt
    {
        $content = \trim($node->bodyRaw());
        [$path, $fallback] = \array_pad(\explode('|', $content, 2), 2, '');
        $path = \trim($path);
        $expression = \str_starts_with($path, '$') ? $path : '$' . $path;
        $fallback = \trim($fallback);

        return new RawPhpStmt(
            '<?php if(' . ($negate ? '!' : '') . 'empty(' . $expression . ' ))echo \''
            . \addslashes($fallback) . '\'?>',
            $node->span(),
        );
    }
}
