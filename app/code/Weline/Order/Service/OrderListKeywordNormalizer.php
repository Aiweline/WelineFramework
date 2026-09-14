<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * Normalize admin order-list keyword so G-{checkout_group_uuid short} searches work.
 *
 * Frontend account groups show display_number = "G-" + substr(checkout_group_uuid, 0, 8).
 * Backend order_number is a separate DisplayNumber (e.g. 9779203997).
 */
final class OrderListKeywordNormalizer
{
    /**
     * @return list<string> Non-empty unique tokens for LIKE matching
     */
    public static function tokens(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $tokens = [$raw];
        if (preg_match('/^G[-_](.+)$/i', $raw, $m) === 1) {
            $stripped = trim((string)$m[1]);
            if ($stripped !== '') {
                $tokens[] = $stripped;
            }
        }

        $out = [];
        $seen = [];
        foreach ($tokens as $token) {
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $out[] = $token;
        }

        return $out;
    }

    /**
     * Build G-{first 8 of checkout_group_uuid} for admin list correlation.
     */
    public static function groupDisplayNumber(string $checkoutGroupUuid): string
    {
        $uuid = trim($checkoutGroupUuid);
        if ($uuid === '') {
            return '';
        }

        return 'G-' . substr($uuid, 0, 8);
    }
}
