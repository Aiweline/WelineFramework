<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

/**
 * Aggregates per-menu unread counts for JS badge painting (never SSR).
 */
final class BackendMenuSignalAggregator
{
    public function __construct(
        private readonly BackendMenuSignalProviderRegistry $registry,
    ) {
    }

    /**
     * @return array{total:int, by_code: array<string, int>}
     */
    public function aggregate(int $backendUserId): array
    {
        $backendUserId = max(0, $backendUserId);
        $byCode = [];
        $total = 0;
        if ($backendUserId <= 0) {
            return ['total' => 0, 'by_code' => []];
        }

        foreach ($this->registry->all() as $provider) {
            $code = trim($provider->code());
            if ($code === '') {
                continue;
            }
            try {
                $count = max(0, (int)$provider->count($backendUserId));
            } catch (\Throwable $e) {
                if (function_exists('w_log_error')) {
                    w_log_error('Backend menu signal count failed: ' . $code . ' ' . $e->getMessage());
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
