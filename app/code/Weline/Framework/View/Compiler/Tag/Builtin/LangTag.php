<?php

declare(strict_types=1);

/**
 * `lang` 标签（G-A 组第一个转正对象）。
 *
 * 语料权重：元素形态 5,302 处 + 内联形态 945 处，是全部待迁移标签的 78%，
 * 所以它必须第一个做，而且必须做到**与旧实现逐字节同源**。
 *
 * 本类的实现是从 `Taglib::getTags()['lang']['callback']` 逐条搬运的：
 * 分支顺序、`addslashes`/`var_export` 的用法、`args` 属性的处理、内联形态的
 * 「逗号分隔参数」「整串加引号」「PHP 表达式」三种判定全部原样保留。
 *
 * 迁移期这是**有意的临时重复**（G1 的最终态由 P6 删旧回调达成）：
 * 在它转正之前，旧编译器仍是唯一真相，`LangTagTest` 用真实 `Taglib::compile()`
 * 输出做比对，证明两边同源；差异一旦出现，测试红，不会静默漂移。
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

final class LangTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'lang',
            attributes: ['args' => AttributeSpec::expr()],
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static fn (TagNode $node, mixed $context): array => [self::lowerElement($node, $context)],
            inlineLower: static fn (InlineNode $node, mixed $context): array => [self::lowerInline($node)],
        );
    }

    private static function lowerElement(TagNode $node, mixed $context): Stmt
    {
        $content = $context instanceof LowerContext ? $context->children($node) : '';
        // 旧编译器对「内容恰好为空」的 <lang></lang> / <lang/> 不产出任何字节；
        // 但内容为一个空格时仍产出空串标记（trim 后为空）。这处差异必须原样保留。
        if ($content === '') {
            return new RawPhpStmt('', $node->span());
        }
        $word = \trim($content);
        $attributes = $node->attributes();

        if (isset($attributes['args']) && \trim((string)$attributes['args']) !== '') {
            return new RawPhpStmt(
                self::openTag() . '=__(\'' . \addslashes($word) . '\', ' . $attributes['args'] . ')' . self::closeTag(),
                $node->span(),
            );
        }

        return new RawPhpStmt(self::emit($word, false), $node->span());
    }

    private static function lowerInline(InlineNode $node): Stmt
    {
        $content = \trim($node->bodyRaw());
        // 旧 `adjustLangInlineAttributeContext()`：token 被成对引号包住时消费引号，
        // 并把 `emit(..., false)` 改成 `emit(..., true)`（JS 字符串里也会发生）。
        $forAttribute = $node->quoted();

        if (\preg_match('/^([^,]+?)\s*,\s*(.+)$/', $content, $matches) === 1) {
            $word = \trim($matches[1]);
            $word = \trim($word, '\'"');
            if ($forAttribute) {
                return new RawPhpStmt(
                    self::openTag()
                    . '=\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::emit('
                    . \var_export($word, true) . ', ' . \trim($matches[2]) . ', true)'
                    . self::closeTag(),
                    $node->span(),
                );
            }

            return new RawPhpStmt(
                self::openTag() . '=__(\'' . \addslashes($word) . '\', ' . \trim($matches[2]) . ')' . self::closeTag(),
                $node->span(),
            );
        }

        $word = \trim($content);
        if (\preg_match('/^([\'"])(.*)\1$/', $word, $matches) === 1) {
            return new RawPhpStmt(self::emit($matches[2], $forAttribute), $node->span());
        }

        $isPhpExpression = \str_starts_with($word, '$')
            || \str_contains($word, '->')
            || \str_contains($word, '::')
            || \preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $word) === 1;
        if ($isPhpExpression) {
            if ($forAttribute) {
                return new RawPhpStmt(
                    self::openTag()
                    . '=\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::emit('
                    . $content . ', true)'
                    . self::closeTag(),
                    $node->span(),
                );
            }

            return new RawPhpStmt(self::openTag() . '=__(' . $content . ')' . self::closeTag(), $node->span());
        }

        return new RawPhpStmt(self::emit($word, $forAttribute), $node->span());
    }

    /**
     * 与旧 `$emit` 闭包逐字一致：有 InlinePhraseMarker 走标记，否则退回 `__()`。
     */
    private static function emit(string $word, bool $forAttribute): string
    {
        $word = \trim($word);
        $exported = \var_export($word, true);
        $flag = $forAttribute ? 'true' : 'false';

        if (\class_exists(\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::class)) {
            return self::openTag()
                . '=\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::emit('
                . $exported . ', ' . $flag . ')'
                . self::closeTag();
        }

        return self::openTag() . '=__(' . $exported . ')' . self::closeTag();
    }

    /**
     * 拼接构造，避免源码里出现字面量开/闭标签（与旧实现同一手法）。
     */
    private static function openTag(): string
    {
        return '<' . '?';
    }

    private static function closeTag(): string
    {
        return '?' . '>';
    }
}
