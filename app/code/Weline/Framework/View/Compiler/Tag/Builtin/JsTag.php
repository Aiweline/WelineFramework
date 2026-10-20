<?php

declare(strict_types=1);

/**
 * `js` 标签（内置；元素 + 内联）。
 *
 * 旧 `Taglib::getTags()['js']['callback']` 的行为很窄，必须原样搬运：
 * - 路径来源：成对标签取子内容（`$tag_data[1]`/`[2]` 里带 `::` 或 `/` 的一方），
 *   内联标签取括号/花括号里的 body；
 * - 属性：按解析后的属性顺序拼 `name="escaped"`，布尔/空值输出裸属性名；
 * - 产物：`<script{attrs} src='{fetchTagSource(STATICS, path)}'></script>`（src 用单引号）。
 *
 * 注意：路径为空在 DEV 下旧链会抛 `TemplateException`；这里保留显式失败，
 * 不静默输出空 src。
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

final class JsTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'js',
            attributes: [
                'type' => AttributeSpec::any(),
                'async' => AttributeSpec::any(),
                'defer' => AttributeSpec::any(),
                'crossorigin' => AttributeSpec::any(),
                'integrity' => AttributeSpec::any(),
                'nomodule' => AttributeSpec::any(),
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
        $path = '';
        if ($context instanceof LowerContext) {
            $path = \trim($context->children($node));
        }
        // 兼容 <js path="..."/> 这类显式属性写法（旧链在无子内容时同样取路径语义）。
        if ($path === '') {
            $attributes = $node->attributes();
            $path = \trim((string)($attributes['path'] ?? $attributes['src'] ?? ''));
        }

        return new RawPhpStmt(
            self::scriptTag($path, $node->attributes(), $context, $node),
            $node->span(),
        );
    }

    private static function lowerInline(InlineNode $node, mixed $context): Stmt
    {
        return new RawPhpStmt(self::scriptTag(\trim($node->bodyRaw()), [], $context, $node), $node->span());
    }

    /**
     * @param array<string, string|true> $attributes
     */
    private static function scriptTag(string $path, array $attributes, mixed $context, TagNode|InlineNode $node): string
    {
        $path = \trim($path);
        if ($path === '') {
            throw new TemplateException(
                '<js> 标签路径不能为空，示例：<js type="module">Vendor_Module::assets/js/app.js</js>',
            );
        }

        $template = $context instanceof LowerContext ? $context->template() : null;
        if ($template === null) {
            throw new \LogicException('js 标签需要只读 Template 注入（Lower 第 4 个参数）');
        }

        $url = $template->fetchTagSource(DataInterface::dir_type_STATICS, $path);
        $attrs = self::attributeString($attributes);

        return '<script' . $attrs . " src='" . $url . "'></script>";
    }

    /**
     * 与旧 callback 的属性拼接逐字一致：布尔/空值裸属性，其余 `name="htmlspecialchars(...)"`。
     *
     * @param array<string, string|true> $attributes
     */
    private static function attributeString(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $name = \trim((string)$name);
            if ($name === '' || \preg_match('/^[a-zA-Z_:][\w:.-]*$/', $name) !== 1) {
                continue;
            }
            if ($value === true || $value === '' || $value === null) {
                $parts[] = $name;
                continue;
            }
            $parts[] = $name . '="'
                . \htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"';
        }

        return $parts === [] ? '' : ' ' . \implode(' ', $parts);
    }
}
