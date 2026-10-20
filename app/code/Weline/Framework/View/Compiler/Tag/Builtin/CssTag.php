<?php

declare(strict_types=1);

/**
 * `css` 标签（元素 + 内联）。
 *
 * 旧 `Taglib::getTags()['css']['callback']` 产物：
 * `<link{attrs} href='{fetchTagSource(STATICS, path)}' rel="stylesheet" type="text/css"/>`
 *
 * 与 `js` 的差异只有输出标签与属性位置；路径/属性判定顺序照旧。
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
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Exception\TemplateException;

final class CssTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'css',
            attributes: [
                'media' => AttributeSpec::any(),
                'type' => AttributeSpec::any(),
                'rel' => AttributeSpec::any(),
                'id' => AttributeSpec::any(),
                'class' => AttributeSpec::any(),
            ],
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerElement($node, $context)],
            inlineLower: static fn (InlineNode $node, mixed $context): array => [self::lowerInline($node, $context)],
        );
    }

    private static function lowerElement(TagNode $node, mixed $context): Stmt
    {
        $path = $context instanceof LowerContext ? \trim($context->children($node)) : '';
        if ($path === '') {
            $attributes = $node->attributes();
            $path = \trim((string)($attributes['href'] ?? $attributes['path'] ?? ''));
        }

        return new RawPhpStmt(self::linkTag($path, $context), $node->span());
    }

    private static function lowerInline(InlineNode $node, mixed $context): Stmt
    {
        return new RawPhpStmt(self::linkTag(\trim($node->bodyRaw()), $context), $node->span());
    }

    private static function linkTag(string $path, mixed $context): string
    {
        $path = \trim($path);
        if ($path === '') {
            throw new TemplateException('<css> 标签路径不能为空，示例：<css>Vendor_Module::css/app.css</css>');
        }

        $template = $context instanceof LowerContext ? $context->template() : null;
        if ($template === null) {
            throw new \LogicException('css 标签需要只读 Template 注入（Lower 第 4 个参数）');
        }

        $url = $template->fetchTagSource(DataInterface::dir_type_STATICS, $path);

        // 旧 css callback 只在 raw1 不是路径时把 raw1 当属性；正常 `<css>path</css>`
        // 不会输出任何属性（media 等会被忽略），这里保持同字节。
        return "<link href='" . $url . "' rel=\"stylesheet\" type=\"text/css\"/>";
    }

}
