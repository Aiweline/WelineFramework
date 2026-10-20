<?php

declare(strict_types=1);

/**
 * `csrf` 标签（元素 + 内联）。
 *
 * 旧链在编译期调用 `Csrf::render($name)` 生成 hidden input；token 每次随机，
 * 因此影子比对必须把 value 归一化（见 `ShadowRunner::normalizeVolatile()`）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block\Csrf;
use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Ir\Stmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class CsrfTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'csrf',
            attributes: ['name' => AttributeSpec::any()],
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
        $attributes = $node->attributes();
        $name = \trim((string)($attributes['name'] ?? ''));
        if ($name === '' && $context instanceof LowerContext) {
            $name = \trim($context->children($node));
        }
        if ($name === '') {
            $name = 'csrf';
        }

        return new RawPhpStmt(self::render($name), $node->span());
    }

    private static function lowerInline(InlineNode $node): Stmt
    {
        $name = \trim($node->bodyRaw());
        if ($name === '') {
            $name = 'csrf';
        }

        return new RawPhpStmt(self::render($name), $node->span());
    }

    private static function render(string $name): string
    {
        /** @var Csrf $csrf */
        $csrf = ObjectManager::getInstance(Csrf::class);

        return $csrf->render($name);
    }
}
