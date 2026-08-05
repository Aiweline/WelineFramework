<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

/**
 * shadow：主/影子候选比对 + MIG observe（版本映射守恒）。
 */
final class B2BShadowComparator
{
    public function __construct(
        private readonly B2BPriceEngine $primary,
        private readonly ?B2BPriceEngine $shadow = null,
    ) {
    }

    public static function forTesting(): self
    {
        return new self(B2BPriceEngine::forTesting(), B2BPriceEngine::forTesting());
    }

    public static function forEngine(B2BPriceEngine $engine): self
    {
        return new self($engine, null);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{ok:bool,unclassified_diff_count:int,primary:array<string,mixed>,shadow:array<string,mixed>,diffs:list<array<string,mixed>>}
     */
    public function compare(array $request): array
    {
        $shadow = $this->shadow ?? $this->primary;
        $a = $this->primary->resolve($request);
        $b = $shadow->resolve($request);
        $keys = ['ok', 'source', 'amount_minor', 'price_list_id', 'version', 'group_id'];
        $diffs = [];
        foreach ($keys as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                $diffs[] = ['field' => $key, 'primary' => $a[$key] ?? null, 'shadow' => $b[$key] ?? null];
            }
        }

        return [
            'ok' => $diffs === [],
            'unclassified_diff_count' => count($diffs),
            'primary' => $a,
            'shadow' => $b,
            'diffs' => $diffs,
        ];
    }

    /**
     * @param list<array{
     *   customer_id:string,
     *   website_id:int,
     *   sku:string,
     *   retail_amount_minor:int,
     *   channel_id?:string|null,
     *   expected_amount_minor:int,
     *   expected_price_list_id:string,
     *   expected_version:int
     * }> $samples
     * @param array<string, array{group_id:string,version:int,list_id:string}> $versionMapping
     * @return array<string, mixed>
     */
    public function observe(array $samples, array $versionMapping): array
    {
        $diffs = [];
        $matched = 0;
        foreach ($samples as $index => $sample) {
            $resolved = $this->primary->resolve([
                'customer_id' => (string) $sample['customer_id'],
                'website_id' => (int) $sample['website_id'],
                'sku' => (string) $sample['sku'],
                'retail_amount_minor' => (int) $sample['retail_amount_minor'],
                'channel_id' => $sample['channel_id'] ?? null,
            ]);
            $expectedAmount = (int) $sample['expected_amount_minor'];
            $expectedList = (string) $sample['expected_price_list_id'];
            $expectedVersion = (int) $sample['expected_version'];

            if (!($resolved['ok'] ?? false)
                || (int) ($resolved['amount_minor'] ?? -1) !== $expectedAmount
                || (string) ($resolved['price_list_id'] ?? '') !== $expectedList
                || (int) ($resolved['version'] ?? -1) !== $expectedVersion
            ) {
                $diffs[] = [
                    'code' => 'candidate_mapping_mismatch',
                    'index' => $index,
                    'expected' => [
                        'amount_minor' => $expectedAmount,
                        'price_list_id' => $expectedList,
                        'version' => $expectedVersion,
                    ],
                    'actual' => $resolved,
                ];
                continue;
            }

            $mapKey = $expectedList . '@v' . $expectedVersion;
            if (!isset($versionMapping[$mapKey])) {
                $diffs[] = [
                    'code' => 'version_mapping_missing',
                    'index' => $index,
                    'map_key' => $mapKey,
                ];
                continue;
            }
            $matched++;
        }

        return [
            'ok' => $diffs === [],
            'sample_count' => count($samples),
            'matched_count' => $matched,
            'mapping_count' => count($versionMapping),
            'unclassified_diff_count' => count($diffs),
            'diffs' => $diffs,
            'conserved' => $diffs === [],
        ];
    }

    public function primary(): B2BPriceEngine
    {
        return $this->primary;
    }

    public function shadow(): ?B2BPriceEngine
    {
        return $this->shadow;
    }
}
