<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Api\Carrier\CarrierCoverageProviderInterface;
use Weline\Shipping\Model\CarrierRegion;

/**
 * Shipping 内置默认：默认可达市场国家级覆盖（见 data/default-markets/countries.tsv）。
 */
final class DefaultCarrierCoverageProvider implements CarrierCoverageProviderInterface
{
    public function providerCode(): string
    {
        return 'default';
    }

    public function defaultCoverage(): array
    {
        $path = BP . 'app/code/Weline/Shipping/data/default-markets/countries.tsv';
        $rows = [];
        $seen = [];
        if (is_file($path)) {
            $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($raw)) {
                foreach ($raw as $i => $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#') || ($i === 0 && str_contains($line, 'country_code'))) {
                        continue;
                    }
                    $parts = preg_split("/\t|,\s*/", $line) ?: [];
                    $cc = strtoupper(trim((string)($parts[0] ?? '')));
                    if ($cc === '' || isset($seen[$cc])) {
                        continue;
                    }
                    $seen[$cc] = true;
                    $rows[] = [
                        'region_type' => CarrierRegion::TYPE_COUNTRY,
                        'country_code' => $cc,
                        'region_id' => null,
                        'region_code' => $cc,
                        'street_id' => null,
                    ];
                }
            }
        }
        if ($rows !== []) {
            return $rows;
        }

        // 文件缺失时至少保留中国，避免承运商无法启用。
        return [
            [
                'region_type' => CarrierRegion::TYPE_COUNTRY,
                'country_code' => 'CN',
                'region_id' => null,
                'region_code' => 'CN',
                'street_id' => null,
            ],
        ];
    }
}
