<?php

declare(strict_types=1);

namespace Weline\Product\Service\HanfuCleanup;

final class HanfuTestCatalogSelection
{
    /** @return list<int> */
    public function productIds(): array
    {
        return [
            1, 2, 5, 6, 7, 8, 9, 10, 11, 12,
            13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
            23, 24, 25, 26, 27, 28, 36, 39, 45,
        ];
    }

    /** @param array<string|int, mixed> $snapshot */
    public function digest(array $snapshot): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($snapshot),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    public function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            $normalized = array_map(
                fn(mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
            usort(
                $normalized,
                static fn(mixed $left, mixed $right): int => json_encode(
                    $left,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ) <=> json_encode(
                    $right,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            );
            return $normalized;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? $this->canonicalize($item) : $item;
        }
        return $value;
    }
}
