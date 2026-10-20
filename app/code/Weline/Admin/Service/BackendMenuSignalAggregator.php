<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Admin\Api\BackendMenuSignalItemsProviderInterface;

/**
 * Aggregates per-menu unread counts (+ optional deep-link items) for JS badge painting (never SSR).
 */
final class BackendMenuSignalAggregator
{
    public const MAX_ITEMS_PER_CODE = 20;

    public function __construct(
        private readonly BackendMenuSignalProviderRegistry $registry,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     by_code: array<string, array{count: int, items: list<array{id: string, title: string, href: string, count: int}>, primary_href: string}>
     * }
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

            $items = [];
            if ($provider instanceof BackendMenuSignalItemsProviderInterface) {
                try {
                    $items = $this->normalizeItems($provider->items($backendUserId));
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Backend menu signal items failed: ' . $code . ' ' . $e->getMessage());
                    }
                    $items = [];
                }
                if ($items === []) {
                    continue;
                }
                $unreadShown = 0;
                foreach ($items as $item) {
                    $unreadShown += max(1, (int)($item['count'] ?? 1));
                }
                if (count($items) < self::MAX_ITEMS_PER_CODE || $count < $unreadShown) {
                    $count = $unreadShown;
                }
            }

            $primaryHref = '';
            if ($items !== []) {
                $primaryHref = (string)($items[0]['href'] ?? '');
            }

            $byCode[$code] = [
                'count' => $count,
                'items' => $items,
                'primary_href' => $primaryHref,
            ];
            $total += $count;
        }

        return [
            'total' => $total,
            'by_code' => $byCode,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{id: string, title: string, href: string, count: int, unread: true}>
     */
    private function normalizeItems(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $href = trim((string)($row['href'] ?? ''));
            if ($href === '' || !$this->isUnreadFlag($row['unread'] ?? null)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $title = $id !== '' ? $id : $href;
            }
            $out[] = [
                'id' => $id !== '' ? $id : $href,
                'title' => $title,
                'href' => $href,
                'count' => max(1, (int)($row['count'] ?? 1)),
                'unread' => true,
            ];
            if (count($out) >= self::MAX_ITEMS_PER_CODE) {
                break;
            }
        }

        return $out;
    }

    private function isUnreadFlag(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        $normalized = strtolower(trim($value));

        return $normalized === '1' || $normalized === 'true';
    }
}
