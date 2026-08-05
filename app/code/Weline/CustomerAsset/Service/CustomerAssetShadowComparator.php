<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

/**
 * MIG observe：余额 / ledger / 预占守恒。
 */
final class CustomerAssetShadowComparator
{
    public function __construct(private readonly CustomerAssetService $assets)
    {
    }

    public static function forService(CustomerAssetService $assets): self
    {
        return new self($assets);
    }

    /**
     * @param list<array{
     *   customer_id:string,
     *   website_id:int,
     *   asset_code:string,
     *   namespace:string,
     *   expected_available:int,
     *   expected_reserved:int,
     *   expected_ledger_min:int
     * }> $samples
     * @return array<string, mixed>
     */
    public function observe(array $samples): array
    {
        $diffs = [];
        $matched = 0;
        $ledgerTotal = $this->assets->ledger()->count();
        foreach ($samples as $index => $sample) {
            $balance = $this->assets->getBalance(
                (string) $sample['customer_id'],
                (int) $sample['website_id'],
                (string) $sample['asset_code'],
                (string) $sample['namespace'],
            );
            $available = (int) ($balance['available_minor'] ?? -1);
            $reserved = (int) ($balance['reserved_minor'] ?? -1);
            if ($available !== (int) $sample['expected_available']
                || $reserved !== (int) $sample['expected_reserved']
            ) {
                $diffs[] = [
                    'code' => 'balance_mismatch',
                    'index' => $index,
                    'expected' => [
                        'available' => (int) $sample['expected_available'],
                        'reserved' => (int) $sample['expected_reserved'],
                    ],
                    'actual' => [
                        'available' => $available,
                        'reserved' => $reserved,
                    ],
                ];
                continue;
            }
            if ($ledgerTotal < (int) $sample['expected_ledger_min']) {
                $diffs[] = [
                    'code' => 'ledger_count_too_low',
                    'index' => $index,
                    'expected_min' => (int) $sample['expected_ledger_min'],
                    'actual' => $ledgerTotal,
                ];
                continue;
            }
            $matched++;
        }

        return [
            'ok' => $diffs === [],
            'sample_count' => count($samples),
            'matched_count' => $matched,
            'ledger_count' => $ledgerTotal,
            'unclassified_diff_count' => count($diffs),
            'diffs' => $diffs,
            'conserved' => $diffs === [],
        ];
    }
}
