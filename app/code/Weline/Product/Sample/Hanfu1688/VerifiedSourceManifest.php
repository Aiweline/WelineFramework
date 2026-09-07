<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class VerifiedSourceManifest
{
    public const CONTRACT = 'hanfu.1688.sources.v1';

    /**
     * @param list<array<string,mixed>> $brands
     * @param list<array<string,mixed>> $suppliers
     * @param list<array<string,mixed>> $links
     * @return array<string,mixed>
     */
    public function seed(int $websiteId, array $brands, array $suppliers, array $links): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('hanfu_1688_website_invalid');
        }

        return [
            'contract' => self::CONTRACT,
            'website_id' => $websiteId,
            'baseline' => [
                'brands' => $this->normalizeRows($brands),
                'suppliers' => $this->normalizeRows($suppliers),
                'links' => $this->normalizeRows($links),
            ],
            'brand_outcomes' => [],
            'verified_sources' => [],
            'aliases' => [],
            'supplier_decisions' => [],
            'revision_history' => [],
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @param array{brands:list<array<string,mixed>>,suppliers:list<array<string,mixed>>,links:list<array<string,mixed>>} $liveBaseline
     * @return array<string,mixed>
     */
    public function validate(int $websiteId, array $document, array $liveBaseline): array
    {
        if (($document['contract'] ?? null) !== self::CONTRACT
            || (int)($document['website_id'] ?? -1) !== $websiteId
        ) {
            throw new \RuntimeException('hanfu_1688_source_contract_invalid');
        }

        $liveBrands = [];
        foreach ($liveBaseline['brands'] ?? [] as $brand) {
            $code = strtolower(trim((string)($brand['code'] ?? '')));
            if ($code === '') {
                throw new \RuntimeException('hanfu_1688_live_brand_invalid');
            }
            $liveBrands[$code] = true;
        }

        $outcomes = [];
        foreach ($document['brand_outcomes'] ?? [] as $outcome) {
            if (!is_array($outcome)) {
                throw new \RuntimeException('hanfu_1688_brand_outcome_invalid');
            }
            $code = strtolower(trim((string)($outcome['brand_code'] ?? '')));
            $terminal = strtolower(trim((string)($outcome['outcome'] ?? '')));
            if (!isset($liveBrands[$code])
                || isset($outcomes[$code])
                || !in_array($terminal, ['verified', 'unmatched', 'duplicate_alias'], true)
            ) {
                throw new \RuntimeException('hanfu_1688_brand_outcome_invalid');
            }
            $outcomes[$code] = $outcome + ['brand_code' => $code, 'outcome' => $terminal];
        }
        if (array_keys($liveBrands) !== array_keys(array_intersect_key($liveBrands, $outcomes))
            || count($outcomes) !== count($liveBrands)
        ) {
            throw new \RuntimeException('hanfu_1688_brand_coverage_incomplete');
        }

        $aliases = [];
        foreach (($document['aliases'] ?? []) as $alias => $canonical) {
            $alias = strtolower(trim((string)$alias));
            $canonical = strtolower(trim((string)$canonical));
            if ($alias === '' || $canonical === '' || $alias === $canonical
                || !isset($liveBrands[$alias], $liveBrands[$canonical])
            ) {
                throw new \RuntimeException('hanfu_1688_alias_invalid');
            }
            $aliases[$alias] = $canonical;
        }
        foreach ($aliases as $alias => $canonical) {
            $seen = [$alias => true];
            while (isset($aliases[$canonical])) {
                if (isset($seen[$canonical])) {
                    throw new \RuntimeException('hanfu_1688_alias_cycle');
                }
                $seen[$canonical] = true;
                $canonical = $aliases[$canonical];
            }
        }

        $sourcesByBrand = [];
        $sourceCodes = [];
        foreach ($document['verified_sources'] ?? [] as $source) {
            if (!is_array($source)) {
                throw new \RuntimeException('hanfu_1688_source_invalid');
            }
            $sourceCode = strtolower(trim((string)($source['source_code'] ?? '')));
            $brandCode = strtolower(trim((string)($source['brand_code'] ?? '')));
            $supplierCode = strtolower(trim((string)($source['supplier_code'] ?? '')));
            if ($sourceCode === '' || $supplierCode === '' || !isset($liveBrands[$brandCode])
                || isset($sourceCodes[$sourceCode])
            ) {
                throw new \RuntimeException('hanfu_1688_source_invalid');
            }
            foreach (['shop_url', 'factory_url'] as $field) {
                if (!$this->isVerified1688Url((string)($source[$field] ?? ''))) {
                    throw new \RuntimeException('hanfu_1688_source_url_invalid');
                }
            }
            $evidenceUrls = $source['evidence_urls'] ?? [];
            if (!is_array($evidenceUrls) || $evidenceUrls === []) {
                throw new \RuntimeException('hanfu_1688_source_evidence_missing');
            }
            foreach ($evidenceUrls as $url) {
                if (!$this->isVerified1688Url((string)$url)) {
                    throw new \RuntimeException('hanfu_1688_source_evidence_invalid');
                }
            }
            if (trim((string)($source['company_name'] ?? '')) === '') {
                throw new \RuntimeException('hanfu_1688_source_company_missing');
            }
            $sourceCodes[$sourceCode] = true;
            $sourcesByBrand[$brandCode] = true;
        }

        foreach ($outcomes as $code => $outcome) {
            if ($outcome['outcome'] === 'verified' && !isset($sourcesByBrand[$code])) {
                throw new \RuntimeException('hanfu_1688_verified_brand_source_missing');
            }
            if ($outcome['outcome'] === 'duplicate_alias') {
                $canonical = strtolower(trim((string)($outcome['canonical_brand_code'] ?? '')));
                if (($aliases[$code] ?? null) !== $canonical) {
                    throw new \RuntimeException('hanfu_1688_alias_outcome_mismatch');
                }
            }
        }

        $validated = $document;
        $validated['aliases'] = $aliases;
        $validated['brand_outcomes'] = array_values($outcomes);
        $validated['source_digest'] = $this->digest($validated);

        return $validated;
    }

    /** @param array<string,mixed> $document */
    public function digest(array $document): string
    {
        unset($document['source_digest']);
        $normalized = $this->canonicalize($document);
        return hash('sha256', json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeRows(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        usort($rows, static fn(array $left, array $right): int => strcmp(
            json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        return $rows;
    }

    private function isVerified1688Url(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        return $host === '1688.com' || str_ends_with($host, '.1688.com');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $value = array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
            usort($value, static fn(mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ));
            return $value;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
