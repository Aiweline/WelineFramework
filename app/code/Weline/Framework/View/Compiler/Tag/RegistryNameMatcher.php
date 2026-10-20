<?php

declare(strict_types=1);

/**
 * 用 Registry 充当 Parser 的标签名单来源（闭合「单一定义源」）。
 *
 * 名单规则完全由描述符声明决定：
 * - `elementForm`：允许 `<name ...>` / `<name/>` / `<name>...</name>`；
 * - `inlineForm`：允许 `@name(...)` / `@name{...}`。
 *
 * `$extra*` 是迁移期的内置标签（`lang`/`if`/`foreach` 仍由旧 `Taglib` 定义），
 * P1 期间由调用方显式注入；等内置标签也搬进 Registry 后这两个参数就该消失。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

final class RegistryNameMatcher implements TagNameMatcherInterface
{
    /** @var array<string, true> */
    private array $extraElement = [];

    /** @var array<string, true> */
    private array $extraInline = [];

    /**
     * @param iterable<string> $extraElementNames
     * @param iterable<string> $extraInlineNames
     */
    public function __construct(
        private readonly Registry $registry,
        iterable $extraElementNames = [],
        iterable $extraInlineNames = [],
    ) {
        foreach ($extraElementNames as $name) {
            foreach ($this->variants($name) as $key) {
                $this->extraElement[$key] = true;
            }
        }
        foreach ($extraInlineNames as $name) {
            foreach ($this->variants($name) as $key) {
                $this->extraInline[$key] = true;
            }
        }
    }

    public function matchesElement(string $name): bool
    {
        $descriptor = $this->registry->get($name);
        if ($descriptor !== null) {
            if ($descriptor->wOnly && !\str_starts_with(\strtolower($name), 'w:')) {
                return false;
            }

            return $descriptor->elementForm;
        }

        foreach ($this->variants($name) as $key) {
            if (isset($this->extraElement[$key])) {
                return true;
            }
        }

        return false;
    }

    public function matchesInline(string $name): bool
    {
        $descriptor = $this->registry->get($name);
        if ($descriptor !== null) {
            return $descriptor->inlineForm;
        }

        foreach ($this->variants($name) as $key) {
            if (isset($this->extraInline[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 名字等价形态：原名 + 去/加 w: 前缀两种写法。
     *
     * @return list<string>
     */
    private function variants(string $name): array
    {
        $key = \strtolower(\trim($name));
        if ($key === '') {
            return [];
        }
        if (\str_starts_with($key, 'w:')) {
            return [$key, \substr($key, 2)];
        }

        return [$key, 'w:' . $key];
    }
}
