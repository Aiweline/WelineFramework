<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

/**
 * Scope DTO shared by panel, Cron, and scanner.
 *
 * @phpstan-type ScopeArray array{
 *   website_id:int,
 *   entity_types?:list<string>,
 *   path_prefix?:string,
 *   locales?:list<string>,
 *   sample_limit?:int,
 *   jaccard_duplicate?:float,
 *   jaccard_suspect?:float,
 *   mode?:string,
 *   notify?:bool,
 *   min_chars?:int
 * }
 */
final class DuplicateCheckScope
{
    public const MODE_SAMPLE = 'sample';
    public const MODE_FULL = 'full';

    /** @var list<string> */
    public readonly array $entityTypes;
    /** @var list<string> */
    public readonly array $locales;

    public function __construct(
        public readonly int $websiteId,
        array $entityTypes = [],
        public readonly string $pathPrefix = '',
        array $locales = [],
        public readonly int $sampleLimit = 500,
        public readonly float $jaccardDuplicate = 0.85,
        public readonly float $jaccardSuspect = 0.70,
        public readonly string $mode = self::MODE_SAMPLE,
        public readonly bool $notify = true,
        public readonly int $minChars = 200,
    ) {
        if ($this->websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be >= 0');
        }
        $this->entityTypes = $this->normalizeStringList($entityTypes);
        $this->locales = $this->normalizeStringList($locales);
        if ($this->sampleLimit < 1) {
            throw new \InvalidArgumentException('sample_limit must be >= 1');
        }
        if ($this->jaccardDuplicate < $this->jaccardSuspect) {
            throw new \InvalidArgumentException('jaccard_duplicate must be >= jaccard_suspect');
        }
        if (!\in_array($this->mode, [self::MODE_SAMPLE, self::MODE_FULL], true)) {
            throw new \InvalidArgumentException('mode must be sample|full');
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $entityTypes = $input['entity_types'] ?? [];
        if (\is_string($entityTypes)) {
            $entityTypes = \preg_split('/\s*,\s*/', $entityTypes) ?: [];
        }
        $locales = $input['locales'] ?? [];
        if (\is_string($locales)) {
            $locales = \preg_split('/\s*,\s*/', $locales) ?: [];
        }

        return new self(
            websiteId: (int)($input['website_id'] ?? -1),
            entityTypes: \is_array($entityTypes) ? $entityTypes : [],
            pathPrefix: \trim((string)($input['path_prefix'] ?? '')),
            locales: \is_array($locales) ? $locales : [],
            sampleLimit: \max(1, (int)($input['sample_limit'] ?? 500)),
            jaccardDuplicate: (float)($input['jaccard_duplicate'] ?? 0.85),
            jaccardSuspect: (float)($input['jaccard_suspect'] ?? 0.70),
            mode: \strtolower(\trim((string)($input['mode'] ?? self::MODE_SAMPLE))) ?: self::MODE_SAMPLE,
            notify: !isset($input['notify']) || (bool)$input['notify'],
            minChars: \max(1, (int)($input['min_chars'] ?? 200)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'entity_types' => $this->entityTypes,
            'path_prefix' => $this->pathPrefix,
            'locales' => $this->locales,
            'sample_limit' => $this->sampleLimit,
            'jaccard_duplicate' => $this->jaccardDuplicate,
            'jaccard_suspect' => $this->jaccardSuspect,
            'mode' => $this->mode,
            'notify' => $this->notify,
            'min_chars' => $this->minChars,
        ];
    }

    /**
     * Filter sitemap URL rows by scope. Comparison buckets stay entity_type(+module fallback)+locale.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function filterUrlRows(array $rows): array
    {
        $prefix = $this->pathPrefix;
        $entityFilter = $this->entityTypes !== [] ? \array_fill_keys($this->entityTypes, true) : null;
        $localeFilter = $this->locales !== [] ? \array_fill_keys($this->locales, true) : null;

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((int)($row['website_id'] ?? -1) !== $this->websiteId) {
                continue;
            }
            $entityType = \trim((string)($row['entity_type'] ?? ''));
            $module = \trim((string)($row['module'] ?? ''));
            $family = $entityType !== '' ? $entityType : $module;
            if ($entityFilter !== null && !isset($entityFilter[$family]) && !isset($entityFilter[$entityType])) {
                continue;
            }
            $locale = (string)($row['locale'] ?? '');
            if ($localeFilter !== null && !isset($localeFilter[$locale])) {
                continue;
            }
            $url = (string)($row['url'] ?? '');
            $urlKey = (string)($row['url_key'] ?? '');
            if ($prefix !== '' && !$this->pathMatches($url, $urlKey, $prefix)) {
                continue;
            }
            $row['_dup_bucket'] = $this->bucketKey($family, $locale);
            $out[] = $row;
        }

        if ($this->mode === self::MODE_SAMPLE && \count($out) > $this->sampleLimit) {
            $out = \array_slice($out, 0, $this->sampleLimit);
        }

        return $out;
    }

    public function bucketKey(string $family, string $locale): string
    {
        return $this->websiteId . '|' . $family . '|' . $locale;
    }

    private function pathMatches(string $url, string $urlKey, string $prefix): bool
    {
        $path = $urlKey;
        if ($path === '' && $url !== '') {
            $parsed = \parse_url($url, \PHP_URL_PATH);
            $path = \is_string($parsed) ? $parsed : $url;
        }
        if ($path === '') {
            return false;
        }
        if ($prefix[0] !== '/' && \str_starts_with($path, '/')) {
            // keep as-is
        }

        return \str_starts_with($path, $prefix) || \str_contains($path, $prefix);
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $s = \trim((string)$v);
            if ($s === '') {
                continue;
            }
            $out[$s] = $s;
        }

        return \array_values($out);
    }
}
