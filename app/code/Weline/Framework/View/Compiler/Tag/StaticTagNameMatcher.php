<?php

declare(strict_types=1);

/**
 * 静态名单判定器（测试与固定名单用）。
 *
 * 默认两种形态共用同一份名单；需要区分时分别传元素名单与内联名单。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

final class StaticTagNameMatcher implements TagNameMatcherInterface
{
    /** @var array<string, true> */
    private array $elementNames = [];

    /** @var array<string, true> */
    private array $inlineNames = [];

    /**
     * @param iterable<string> $elementNames
     * @param iterable<string>|null $inlineNames null = 与元素名单相同
     */
    public function __construct(iterable $elementNames = [], ?iterable $inlineNames = null)
    {
        foreach ($elementNames as $name) {
            $normalized = \strtolower(\trim((string)$name));
            if ($normalized !== '') {
                $this->elementNames[$normalized] = true;
            }
        }
        foreach ($inlineNames ?? $elementNames as $name) {
            $normalized = \strtolower(\trim((string)$name));
            if ($normalized !== '') {
                $this->inlineNames[$normalized] = true;
            }
        }
    }

    public function matchesElement(string $name): bool
    {
        foreach ($this->variants($name) as $key) {
            if (isset($this->elementNames[$key])) {
                return true;
            }
        }

        return false;
    }

    public function matchesInline(string $name): bool
    {
        foreach ($this->variants($name) as $key) {
            if (isset($this->inlineNames[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function variants(string $name): array
    {
        $key = \strtolower(\trim($name));
        if ($key === '') {
            return [];
        }

        return \str_starts_with($key, 'w:') ? [$key, \substr($key, 2)] : [$key, 'w:' . $key];
    }

    /**
     * @return list<string>
     */
    public function elementNames(): array
    {
        return \array_keys($this->elementNames);
    }
}
