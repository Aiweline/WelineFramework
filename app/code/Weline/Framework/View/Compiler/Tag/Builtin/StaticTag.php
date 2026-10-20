<?php

declare(strict_types=1);

/**
 * `static` 标签（B1，413 次）：编译期把路径交给 `$template->fetchTagSource('statics', ...)`。
 *
 * 这是第一个需要**只读 Template 注入**的标签：旧回调在编译期就解析静态资源真实路径，
 * 产物里是解析后的 URL。派生逻辑必须与旧回调一致：
 * 元素形态优先用属性原文（`tag_data[1]`，为空则用子内容），内联形态用 body。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class StaticTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'static',
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static function (TagNode $node, mixed $context): array {
                $path = \trim($node->attributesRaw()) ?: \trim($context instanceof LowerContext ? $context->children($node) : '');

                return [self::emit($node->span(), $path, $context)];
            },
            inlineLower: static function (InlineNode $node, mixed $context): array {
                return [self::emit($node->span(), \trim($node->bodyRaw()), $context)];
            },
        );
    }

    private static function emit(\Weline\Framework\View\Compiler\Lexer\Span $span, string $path, mixed $context): RawPhpStmt
    {
        $template = $context instanceof LowerContext ? $context->template() : null;
        if ($template === null) {
            throw new \LogicException('static 标签需要只读 Template 注入（Lower 第 4 个参数）');
        }

        return new RawPhpStmt((string)$template->fetchTagSource('statics', $path), $span);
    }
}
