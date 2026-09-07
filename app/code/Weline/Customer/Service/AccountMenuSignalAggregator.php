<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

/**
 * Aggregates per-menu unread counts for JS badge painting.
 */
final class AccountMenuSignalAggregator
{
    public function __construct(
        private readonly AccountMenuSignalProviderRegistry $registry,
    ) {
    }

    /**
     * @return array{total:int, by_code: array<string, int>}
     */
    public function aggregate(int $customerId, int $websiteId): array
    {
        $customerId = max(0, $customerId);
        $websiteId = max(0, $websiteId);
        $byCode = [];
        $total = 0;
        if ($customerId <= 0) {
            return ['total' => 0, 'by_code' => []];
        }

        foreach ($this->registry->all() as $provider) {
            $code = strtolower(trim($provider->code()));
            if ($code === '') {
                continue;
            }
            try {
                $count = max(0, (int)$provider->count($customerId, $websiteId));
            } catch (\Throwable $e) {
                if (function_exists('w_log_error')) {
                    w_log_error('Account menu signal count failed: ' . $code . ' ' . $e->getMessage());
                }
                $count = 0;
            }
            if ($count <= 0) {
                continue;
            }
            $byCode[$code] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'by_code' => $byCode,
        ];
    }
}
