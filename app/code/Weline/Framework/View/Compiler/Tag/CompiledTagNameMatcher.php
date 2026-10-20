<?php

declare(strict_types=1);

/**
 * 从编译索引 `generated/taglibs.php` 读标签名，并按**声明形态**分档。
 *
 * 形态来自标签自身的声明字段（`tag` / `tag_start` / `tag_end` / `tag_self_close*`）：
 * 只有声明了元素形态的标签才进 `matchesElement()`，否则像 `Weline_Meta`
 * 这种只支持 `@meta{...}` 的标签会把 HTML `<meta>` 误判成模板标签。
 *
 * 内置标签（`lang`/`if`/`foreach` 等）不在编译索引里，由调用方通过
 * `$extraElementNames` 注入——来源应是 Registry 或旧 `Taglib` 的声明，
 * 本类不维护任何名单。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

final class CompiledTagNameMatcher implements TagNameMatcherInterface
{
    /** @var array<string, true>|null */
    private ?array $elementNames = null;

    /** @var array<string, true>|null */
    private ?array $inlineNames = null;

    /**
     * @param iterable<string> $extraElementNames
     * @param iterable<string> $extraInlineNames
     */
    public function __construct(
        private readonly string $compiledFile,
        private readonly iterable $extraElementNames = [],
        private readonly iterable $extraInlineNames = [],
    ) {
    }

    public function matchesElement(string $name): bool
    {
        return isset($this->elementNames()[\strtolower($name)]);
    }

    public function matchesInline(string $name): bool
    {
        return isset($this->inlineNames()[\strtolower($name)]);
    }

    /**
     * @return array<string, true>
     */
    private function elementNames(): array
    {
        if ($this->elementNames !== null) {
            return $this->elementNames;
        }
        $this->load();

        return $this->elementNames ?? [];
    }

    /**
     * @return array<string, true>
     */
    private function inlineNames(): array
    {
        if ($this->inlineNames !== null) {
            return $this->inlineNames;
        }
        $this->load();

        return $this->inlineNames ?? [];
    }

    private function load(): void
    {
        $elementNames = [];
        $inlineNames = [];

        if (\is_file($this->compiledFile)) {
            try {
                /** @var mixed $registry */
                $registry = require $this->compiledFile;
                if (\is_array($registry) && \is_array($registry['tags'] ?? null)) {
                    foreach ($registry['tags'] as $name => $definition) {
                        $normalized = \strtolower((string)$name);
                        $inlineNames[$normalized] = true;
                        if ($this->declaresElementForm(\is_array($definition) ? $definition : [])) {
                            $elementNames[$normalized] = true;
                        }
                    }
                }
            } catch (\Throwable) {
                // 索引损坏时不阻断：调用方仍可用 extra* 名单工作。
            }
        }

        foreach ($this->extraElementNames as $name) {
            $normalized = \strtolower(\trim((string)$name));
            if ($normalized !== '') {
                $elementNames[$normalized] = true;
                $inlineNames[$normalized] = true;
            }
        }
        foreach ($this->extraInlineNames as $name) {
            $normalized = \strtolower(\trim((string)$name));
            if ($normalized !== '') {
                $inlineNames[$normalized] = true;
            }
        }

        $this->elementNames = $elementNames;
        $this->inlineNames = $inlineNames;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function declaresElementForm(array $definition): bool
    {
        foreach (['tag', 'tag_start', 'tag_end', 'tag_self_close', 'tag_self_close_with_attrs'] as $key) {
            if (!empty($definition[$key])) {
                return true;
            }
        }

        return false;
    }
}
