<?php

declare(strict_types=1);

/**
 * Lower：节点树 → 语句树。
 *
 * 三条纪律：
 * 1. **文本不进 echo**：`TextNode` 原样落成 `TextStmt`，保持 com_*.phtml 的结构（C2）。
 * 2. **未实现 lower 的标签原样降级**：`InlineNode` 与「已注册但 `canLower()=false`」的标签
 *    都落成 `TextStmt(raw)`。这会保留字节（影子比对仍绿），但**语义上是未编译的**，
 *    所以在它们补齐之前 v3 不允许接管真实编译入口（P6 之前不给 `Template` 接线）。
 * 3. **已实现 lower 的标签**：先递归 lower 子节点并 Emit 成字符串，再交给描述符，
 *    与旧 `callback()` 收到的 `$content` 同形。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Document;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\Node;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Parser\PhpNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Parser\TextNode;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class Lower
{
    private readonly Emitter $emitter;

    /** 属性值等片段的内联标签解析器（按已注册标签名单懒建）。 */
    private ?Parser $inlineParser = null;

    /**
     * @param Interpolation|null $interpolation 为 null 时保持 P0 的「逐字节重放」语义
     *        （影子骨架用）；真实编译需要传实例来启用 `{{expr}}` 插值。
     */
    public function __construct(
        private readonly ?Registry $registry = null,
        ?Emitter $emitter = null,
        private readonly ?Interpolation $interpolation = null,
        private readonly ?Template $template = null,
    ) {
        $this->emitter = $emitter ?? new Emitter();
    }

    /**
     * @return list<Stmt>
     */
    public function lower(Document $document): array
    {
        return $this->lowerNodes($document->nodes());
    }

    /**
     * @param list<Node> $nodes
     * @return list<Stmt>
     */
    private function lowerNodes(array $nodes): array
    {
        $statements = [];
        foreach ($nodes as $node) {
            $lowered = $this->lowerNode($node);
            if (\is_array($lowered)) {
                foreach ($lowered as $statement) {
                    $statements[] = $statement;
                }
                continue;
            }
            $statements[] = $lowered;
        }

        return $statements;
    }

    /**
     * @return Stmt|list<Stmt>
     */
    private function lowerNode(Node $node): Stmt|array
    {
        if ($node instanceof TextNode) {
            return $this->lowerText($node);
        }
        if ($node instanceof PhpNode) {
            return new RawPhpStmt($node->raw(), $node->span());
        }
        if ($node instanceof InlineNode) {
            return $this->lowerInline($node);
        }
        if ($node instanceof TagNode) {
            return $this->lowerTag($node);
        }

        throw new \LogicException('未支持的节点类型：' . $node::class);
    }

    /**
     * 文本节点：启用插值时切成「文本 + echo 语句」；未启用时原样一条。
     *
     * @return list<Stmt>
     */
    private function lowerText(TextNode $node): array
    {
        if ($this->interpolation === null) {
            return [new TextStmt($node->raw(), $node->span())];
        }

        $statements = [];
        foreach ($this->interpolation->split($node->raw()) as $part) {
            $statements[] = $part['type'] === 'php'
                ? new RawPhpStmt($part['raw'], $node->span())
                : new TextStmt($part['raw'], $node->span());
        }

        return $statements;
    }

    private function lowerInline(InlineNode $node): Stmt
    {
        $descriptor = $this->registry?->get($node->name());
        if ($descriptor === null || !$descriptor->canLowerInline()) {
            // 未实现内联 lower 的标签保持原样（同上：显式降级，不静默改写）。
            return new TextStmt($node->raw(), $node->span());
        }

        $statements = ($descriptor->inlineLower)($node, $this->context());

        return $this->singleStatement($statements, $node->name(), $node->span());
    }

    private function lowerTag(TagNode $node): Stmt
    {
        // 旧链对 w: 前缀的 empty/notempty/has 命中命名空间 wildcard，走 runtime；
        // 无 w: 的形态则由 ConditionTag 直接丢弃（旧 tokenizer 行为）。
        if (\in_array(\strtolower($node->name()), ['w:empty', 'w:notempty', 'w:has'], true)) {
            return $this->lowerRuntimeTag($node);
        }

        $descriptor = $this->registry?->get($node->name());
        if ($descriptor !== null
            && ($descriptor->stage === TagDescriptor::STAGE_RUNTIME
                || ($descriptor->stage === TagDescriptor::STAGE_AUTO && $this->hasDynamicAttributes($node)))) {
            return $this->lowerRuntimeTag($node);
        }
        if ($descriptor === null || !$descriptor->canLower()) {
            // 显式降级：字节保留，但未编译。调用方（影子编译器）能用重放校验发现它。
            return new TextStmt($node->raw(), $node->span());
        }

        $statements = ($descriptor->lower)($node, $this->context());

        if (!\is_array($statements)) {
            throw new \LogicException(\sprintf('标签 %s 的 lower() 必须返回 Stmt 列表', $node->name()));
        }
        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt) {
                throw new \LogicException(\sprintf('标签 %s 的 lower() 返回了非 Stmt 元素', $node->name()));
            }
        }

        return $this->singleStatement($statements, $node->name(), $node->span());
    }

    /**
     * 旧 `StageResolver` 的 runtime 判定：属性里有 PHP / 插值 / 内联标签就必须运行期解析。
     *
     * @param array<string, string|true> $attributes
     */
    private function hasDynamicAttributes(TagNode $node): bool
    {
        foreach ($node->attributes() as $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (\str_contains($value, '<?') || \str_contains($value, '{{') || \str_contains($value, '@')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 与旧 `CodeGenerator::generateRuntimeTag()` 同形的 runtime 下发。
     *
     * `childrenVar` 必须用 `crc32(var_export(name,true) . line)`：旧产物就是这么命名的，
     * 差一个字符影子就会报差异。
     */
    private function lowerRuntimeTag(TagNode $node): Stmt
    {
        $tagName = \var_export($node->name(), true);
        $attributes = $this->runtimeAttributes($node->attributes());

        $children = $this->emitter->emit($this->lowerNodes($node->children()));
        // 空成对 runtime 标签旧 AST 会按自闭合下发（empty slot/form 实测），
        // 不能生成 beginCapture/endCapture 空捕获。
        if ($node->isSelfClosing() || $children === '') {
            return new RawPhpStmt(
                '<?= $this->getTaglib()->renderRuntimeTag($this, ' . $tagName . ", 'tag-self-close', "
                . $attributes . ", '', '', '', '') ?>",
                $node->span(),
            );
        }
        $childrenVar = '$__children_' . \crc32($tagName . $node->legacyLine());

        $code = '<?php \\Weline\\Framework\\Runtime\\FiberOutputBuffer::beginCapture(); ?>'
            . '<?php try { ?>'
            . $children
            . '<?php ' . $childrenVar . ' = \\Weline\\Framework\\Runtime\\FiberOutputBuffer::endCapture(); ?>'
            . '<?php } catch (\\Throwable $__weline_rt_children_e) { ?>'
            . '<?php \\Weline\\Framework\\Runtime\\FiberOutputBuffer::discardCapture(); ?>'
            . '<?php throw $__weline_rt_children_e; ?>'
            . '<?php } ?>'
            . '<?= $this->getTaglib()->renderRuntimeTag($this, ' . $tagName . ", 'tag-start', "
            . $attributes . ', ' . $childrenVar . ", '', '', '') ?>";

        return new RawPhpStmt($code, $node->span());
    }

    /**
     * @param array<string, string|true> $attributes
     */
    private function runtimeAttributes(array $attributes): string
    {
        $pairs = [];
        foreach ($attributes as $name => $value) {
            if (\is_string($value) && \str_contains($value, '@')) {
                // 旧链在 AST 之前就展开内联标签（@url/@lang/@backend-url）；
                // runtime 属性数组必须拿展开后的值，否则会把 @url 原样传进 HTML。
                $value = $this->attributeInlineFragment($value);
            }
            $pairs[] = \var_export((string)$name, true) . ' => ' . $this->runtimeAttributeValue($value);
        }

        return '[' . \implode(', ', $pairs) . ']';
    }

    /**
     * 属性上下文的内联展开：旧 `adjustLangInlineAttributeContext()` 会把
     * `@lang(...)` 的 InlinePhraseMarker flag 从 false 改成 true。
     */
    private function attributeInlineFragment(string $value): string
    {
        $expanded = $this->lowerInlineFragment($value);
        $expanded = (string)\preg_replace(
            '/InlinePhraseMarker::emit\((.*),\s*false\)/s',
            'InlinePhraseMarker::emit($1, true)',
            $expanded,
            1,
        );
        if (!\str_contains($expanded, 'InlinePhraseMarker::emit')
            && \preg_match('/^<\?=\s*__\((.+)\)\s*\?>$/s', \trim($expanded), $matches) === 1) {
            $expanded = '<?=\Weline\I18n\Service\InlineEdit\InlinePhraseMarker::emit('
                . $matches[1] . ', true) ?>';
        }

        return $expanded;
    }

    private function runtimeAttributeValue(string|bool $value): string
    {
        if (!\is_string($value)) {
            // 旧 AttrNode 对裸属性给的是空串，不是 true。
            return \var_export('', true);
        }
        if (!\preg_match_all('/<\?(?:=|php\b)?(.*?)\?>/s', $value, $matches, PREG_OFFSET_CAPTURE)) {
            return \var_export($value, true);
        }

        $parts = [];
        $offset = 0;
        foreach ($matches[0] as $index => $match) {
            [$raw, $position] = $match;
            if ($position > $offset) {
                $parts[] = \var_export(\substr($value, $offset, $position - $offset), true);
            }
            $expr = \trim((string)$matches[1][$index][0]);
            if (\str_starts_with($expr, 'echo ')) {
                $expr = \trim(\substr($expr, 5));
            }
            $expr = \rtrim($expr, ';');
            $parts[] = '(' . $expr . ')';
            $offset = $position + \strlen($raw);
        }
        if ($offset < \strlen($value)) {
            $parts[] = \var_export(\substr($value, $offset), true);
        }

        return \implode(' . ', $parts);
    }

    private function context(): LowerContext
    {
        return new LowerContext(
            fn (TagNode $target): string => $this->emitter->emit($this->lowerNodes($target->children())),
            $this->template,
            fn (string $text): string => $this->lowerInlineFragment($text),
            fn (TagNode $target, int $offset, ?int $length = null): string => $this->emitter->emit(
                $this->lowerNodes(\array_slice($target->children(), $offset, $length)),
            ),
        );
    }

    /**
     * 片段里的内联标签：只编译 `@tag(...)` / `@tag{...}`，元素标签与文本原样保留。
     *
     * 与旧 `expandInlineTags()` 的边界一致：它只替换内联 token，不碰元素标签，
     * 所以 `<lang>` 在属性值里仍是字符串（`block` 的 `placeholder` 依赖这一点）。
     * 不做 `{{}}` 插值：插值由 TextNode 阶段处理，属性值不在其列。
     */
    private function lowerInlineFragment(string $text): string
    {
        if (!\str_contains($text, '@')) {
            return $text;
        }

        $statements = [];
        foreach ($this->inlineFragmentParser()->parse($text)->nodes() as $node) {
            if ($node instanceof InlineNode) {
                $statements[] = $this->lowerInline($node);
                continue;
            }
            if ($node instanceof PhpNode) {
                $statements[] = new RawPhpStmt($node->raw(), $node->span());
                continue;
            }
            $statements[] = new TextStmt($node->raw(), $node->span());
        }

        return $this->emitter->emit($statements);
    }

    private function inlineFragmentParser(): Parser
    {
        if ($this->inlineParser === null) {
            $names = [];
            foreach ($this->registry?->all() ?? [] as $descriptor) {
                if (!$descriptor->inlineForm) {
                    continue;
                }
                foreach ($descriptor->allNames() as $name) {
                    $names[] = $name;
                }
            }
            $this->inlineParser = new Parser(new Lexer(), new StaticTagNameMatcher($names));
        }

        return $this->inlineParser;
    }

    /**
     * 描述符返回多条语句时合并成一条 RawPhp（Emit 后仍是同一段字节）。
     *
     * @param mixed $statements
     */
    private function singleStatement(mixed $statements, string $tagName, \Weline\Framework\View\Compiler\Lexer\Span $span): Stmt
    {
        if (!\is_array($statements)) {
            throw new \LogicException(\sprintf('标签 %s 的 lower() 必须返回 Stmt 列表', $tagName));
        }
        foreach ($statements as $statement) {
            if (!$statement instanceof Stmt) {
                throw new \LogicException(\sprintf('标签 %s 的 lower() 返回了非 Stmt 元素', $tagName));
            }
        }

        return \count($statements) === 1 ? $statements[0] : new RawPhpStmt(
            $this->emitter->emit($statements),
            $span,
        );
    }
}
