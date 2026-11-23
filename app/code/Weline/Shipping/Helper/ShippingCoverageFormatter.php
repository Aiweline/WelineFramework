<?php

declare(strict_types=1);

namespace Weline\Shipping\Helper;

use Weline\Shipping\Api\Data\Shipping\ShippingCoverageRow;

/**
 * 配送覆盖行的展示聚合：同一航线（服务+时效+起运费）合并，避免政策页堆 200 多行。
 */
final class ShippingCoverageFormatter
{
    /**
     * @param list<ShippingCoverageRow> $rows
     * @return list<array{service_code:string,days_min:int,days_max:int,cost_minor:int,currency:string,country_count:int}>
     */
    public static function groupByService(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (!$row instanceof ShippingCoverageRow) {
                continue;
            }
            $key = implode('|', [
                $row->serviceCode,
                (string)$row->daysMin,
                (string)$row->daysMax,
                (string)$row->costMinor,
                $row->currency,
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'service_code' => $row->serviceCode,
                    'days_min' => $row->daysMin,
                    'days_max' => $row->daysMax,
                    'cost_minor' => $row->costMinor,
                    'currency' => $row->currency,
                    'country_count' => 0,
                ];
            }
            $groups[$key]['country_count']++;
        }

        $list = array_values($groups);
        usort($list, static function (array $a, array $b): int {
            $rankA = [$a['days_min'] > 0 ? 0 : 1, $a['days_min'], $a['cost_minor'] > 0 ? $a['cost_minor'] : PHP_INT_MAX, $a['service_code']];
            $rankB = [$b['days_min'] > 0 ? 0 : 1, $b['days_min'], $b['cost_minor'] > 0 ? $b['cost_minor'] : PHP_INT_MAX, $b['service_code']];
            foreach ($rankA as $index => $value) {
                if ($value === $rankB[$index]) {
                    continue;
                }

                return $value <=> $rankB[$index];
            }

            return 0;
        });

        return $list;
    }

    public static function formatMoney(int $minor, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $amount = number_format($minor / 100, 2, '.', '');

        return $currency !== '' ? $amount . ' ' . $currency : $amount;
    }
}
