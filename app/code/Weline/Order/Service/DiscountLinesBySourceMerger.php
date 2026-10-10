<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\OrderAdminAmountAdjustmentPolicyInterface;

/**
 * type_payload.discount_lines 按 source_type 分区合并，避免 incentive/admin 互抹。
 */
final class DiscountLinesBySourceMerger
{
    public const SOURCE_PAYMENT_INCENTIVE = 'payment_method_incentive';

    /**
     * @param list<mixed> $existing
     * @param list<mixed> $incoming
     * @param list<string> $replaceSourceTypes
     * @return list<array<string, mixed>>
     */
    public static function merge(array $existing, array $incoming, array $replaceSourceTypes): array
    {
        $replace = [];
        foreach ($replaceSourceTypes as $type) {
            $normalized = strtolower(trim($type));
            if ($normalized !== '') {
                $replace[$normalized] = true;
            }
        }

        $kept = [];
        foreach ($existing as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $source = strtolower(trim((string)($row['source_type'] ?? '')));
            if ($source !== '' && isset($replace[$source])) {
                continue;
            }
            $kept[] = $row;
        }

        foreach ($incoming as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $source = strtolower(trim((string)($row['source_type'] ?? '')));
            if ($source === '' || !isset($replace[$source])) {
                continue;
            }
            $kept[] = $row;
        }

        return array_values($kept);
    }

    /**
     * @param list<mixed> $lines
     * @return list<array<string, mixed>>
     */
    public static function filterBySource(array $lines, string $sourceType): array
    {
        $want = strtolower(trim($sourceType));
        $out = [];
        foreach ($lines as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (strtolower(trim((string)($row['source_type'] ?? ''))) === $want) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $lines
     */
    public static function absSumForSource(array $lines, string $sourceType): int
    {
        $sum = 0;
        foreach (self::filterBySource($lines, $sourceType) as $row) {
            $sum += abs((int)($row['amount_minor'] ?? 0));
        }

        return $sum;
    }

    public static function adminSource(): string
    {
        return OrderAdminAmountAdjustmentPolicyInterface::SOURCE_TYPE;
    }
}
