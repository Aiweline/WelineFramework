<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/** Exact-source copy used by the catalog's one-off locale backfill. */
final class CuratedProductNameTranslation
{
    /** @param array<string,string> $names */
    public function __construct(private readonly array $names)
    {
    }

    /** @param array{value?:mixed,cleared?:bool}|null $localRow */
    public function replacement(string $sourceName, ?array $localRow = null): ?string
    {
        $sourceName = trim($sourceName);
        $existing = trim((string)($localRow['value'] ?? ''));
        if (($localRow['cleared'] ?? false) || ($existing !== '' && $existing !== $sourceName)) {
            return null;
        }
        $name = trim($this->names[$sourceName] ?? '');
        return $name === '' ? null : $name;
    }
}
