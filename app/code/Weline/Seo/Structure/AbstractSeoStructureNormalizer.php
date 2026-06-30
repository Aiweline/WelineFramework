<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

abstract class AbstractSeoStructureNormalizer
{
    /**
     * @param array<int|string, mixed> $value
     * @return array<int, mixed>
     */
    protected function filterList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => $item !== null && $item !== ''));
    }

    protected function trimString(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $item
     * @param string[] $keys
     */
    protected function firstString(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            $value = $item[$key];
            if (is_array($value)) {
                $nested = $this->firstString($value, ['text', 'name', 'value']);
                if ($nested !== '') {
                    return $nested;
                }
                continue;
            }
            $string = $this->trimString($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }
}
