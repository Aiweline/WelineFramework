<?php

declare(strict_types=1);

/**
 * `template` 标签（元素 + `@template(...)` 内联）。
 *
 * 旧链对两种形态的产物**不同**，不能统一：
 * - 元素（`<template>path</template>` / `<w:template name="..."/>`）：
 *   `<?php include $this->fetchTagSource(dir_type_TEMPLATE, 'path'); ?>`
 *   （`CodeGenerator::generateTemplateTag()` 的硬编码产物）
 * - 内联（`@template(path)`）：直接 `file_get_contents()` 已编译模板并**内联**，
 *   所以旧产物里会带子模板自己的 `compile_id/hash` 头（Taglib callback 的产物）。
 *
 * 路径为空：元素/内联都输出 `<!-- 警告：template 标签缺少模板路径 -->`；
 * 内联 `enable` 在旧链只在 Taglib callback 里判断，而内联展开传的是空 attributes，
 * 因此内联形态不处理 enable，保持与旧链一致。
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

final class TemplateTag
{
    private const MISSING_PATH = '<!-- 警告：template 标签缺少模板路径 -->';

    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'template',
            aliases: ['include'],
            attributes: [
                'name' => AttributeSpec::any(),
                'enable' => AttributeSpec::any(),
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
        $attributes = $node->attributes();
        $path = \trim((string)($attributes['name'] ?? ''));
        if ($path === '' && $context instanceof LowerContext) {
            $path = \trim($context->children($node));
        }
        if ($path === '') {
            $path = \trim((string)\preg_replace('/^<[^>]*>|<\/[^>]*>$/s', '', $node->raw()));
        }
        if ($path === '') {
            return new RawPhpStmt(self::MISSING_PATH, $node->span());
        }

        return new RawPhpStmt(
            '<?php include $this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_TEMPLATE, '
            . \var_export($path, true) . '); ?>',
            $node->span(),
        );
    }

    private static function lowerInline(InlineNode $node, mixed $context): Stmt
    {
        $path = \trim($node->bodyRaw());
        if ($path === '') {
            return new RawPhpStmt(self::MISSING_PATH, $node->span());
        }

        $template = $context instanceof LowerContext ? $context->template() : null;
        if ($template === null) {
            throw new \LogicException('template 标签需要只读 Template 注入（Lower 第 4 个参数）');
        }

        $compiledPath = $template->fetchTagSource(DataInterface::dir_type_TEMPLATE, $path);
        $content = @\file_get_contents($compiledPath);
        if (!\is_string($content)) {
            throw new \RuntimeException('template 标签无法读取编译产物：' . $path);
        }

        return new RawPhpStmt($content, $node->span());
    }
}
