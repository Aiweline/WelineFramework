<?php

declare(strict_types=1);

/**
 * `url` 标签（B1，973 次）：站点内链接。
 *
 * 与旧回调逐分支对齐，注意三处真实差异：
 * 1. 先走编译期烘焙：`$taglib->bakeUrlFamily($template, 'getUrl', $tagKey, $tagData, $attributes)`，
 *    命中就直接用烘焙结果（HTML），未命中才生成 `<?=$this->getUrl(...)?>`；
 * 2. `tag` / `tag-self-close-with-attrs` 分支优先看 `path` 属性；属性是表达式（含 `<?` 或以 `$` 开头）时
 *    回落到子内容按 `|` 拆分；
 * 3. 内联分支把 `|` 后的参数作为**第二个实参**传给 `getUrl($path, $params)`，绝不能按位或。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Lexer\Span;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class UrlTag
{
    public static function descriptor(string $name = 'url', string $method = 'getUrl'): TagDescriptor
    {
        return new TagDescriptor(
            name: $name,
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static function (TagNode $node, mixed $context) use ($method): array {
                return [self::lowerElement($node, $context, $method)];
            },
            inlineLower: static function (InlineNode $node, mixed $context) use ($method): array {
                return [self::lowerInline($node, $context, $method)];
            },
        );
    }

    private static function lowerElement(TagNode $node, mixed $context, string $method): RawPhpStmt
    {
        $children = $context instanceof LowerContext ? $context->children($node) : '';
        $attributes = $node->attributes();
        $tagKey = self::resolveTagKey($node);

        $baked = self::bake($context, $method, $tagKey, [$node->openRaw(), $node->attributesRaw(), $children], $attributes);
        if ($baked !== null) {
            return new RawPhpStmt($baked, $node->span());
        }

        if ($tagKey === 'tag-start') {
            return new RawPhpStmt('<?=$this->' . $method . '(', $node->span());
        }
        if ($tagKey === 'tag-end') {
            return new RawPhpStmt(')?>', $node->span());
        }

        $pathAttr = isset($attributes['path']) ? \trim((string)$attributes['path']) : '';
        if ($pathAttr !== '' && !\str_contains($pathAttr, '<?') && !\str_starts_with($pathAttr, '$')) {
            return new RawPhpStmt('<?=$this->' . $method . '(\'' . \addslashes($pathAttr) . '\')?>', $node->span());
        }

        $parts = \explode('|', $children);
        $path = \str_replace(' ', '', \trim($parts[0] ?? '', "'\""));
        $params = isset($parts[1]) && \trim($parts[1]) !== '' ? ',' . \trim($parts[1]) : '';

        return new RawPhpStmt('<?=$this->' . $method . '(\'' . $path . '\'' . $params . ')?>', $node->span());
    }

    private static function lowerInline(InlineNode $node, mixed $context, string $method): RawPhpStmt
    {
        $baked = self::bake($context, $method, '@tag()', [$node->raw(), $node->bodyRaw()], []);
        if ($baked !== null) {
            return new RawPhpStmt($baked, $node->span());
        }

        $raw = \str_replace(' ', '', $node->bodyRaw());
        $parts = \explode('|', $raw, 2);
        $pathExpr = $parts[0] ?? '';
        if ($pathExpr === '') {
            return new RawPhpStmt('', $node->span());
        }
        $paramsExpr = isset($parts[1]) ? \trim($parts[1]) : '';

        return new RawPhpStmt(
            '<?=$this->' . $method . '(' . $pathExpr . ($paramsExpr !== '' ? ',' . $paramsExpr : '') . ')?>',
            $node->span(),
        );
    }

    private static function resolveTagKey(TagNode $node): string
    {
        if ($node->isSelfClosing()) {
            if ($node->attributesRaw() !== '') {
                return 'tag-self-close-with-attrs';
            }
            if ($node->isPaired()) {
                return 'tag';
            }
        }

        return $node->isPaired() ? 'tag' : 'tag-start';
    }

    /**
     * @param array{0:string,1:string,2:string} $tagData
     * @param array<string, string|true> $attributes
     */
    private static function bake(mixed $context, string $method, string $tagKey, array $tagData, array $attributes): ?string
    {
        if (!$context instanceof LowerContext) {
            return null;
        }
        $template = $context->template();
        if ($template === null) {
            return null;
        }

        return $template->getTaglib()->bakeUrlFamily($template, $method, $tagKey, $tagData, $attributes);
    }
}
