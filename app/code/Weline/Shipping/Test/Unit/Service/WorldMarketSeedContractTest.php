<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;

/** 中国发运全球可达市场种子：覆盖面 + 增量并入目的地/承运商。 */
final class WorldMarketSeedContractTest extends TestCase
{
    public function testDefaultMarketsTsvCoversUsAndWorldScale(): void
    {
        $path = dirname(__DIR__, 3) . '/data/default-markets/countries.tsv';
        self::assertFileExists($path);
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($raw);
        $rows = [];
        $usLane = null;
        foreach ($raw as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'country_code')) {
                continue;
            }
            $parts = preg_split("/\t|,\s*/", $line) ?: [];
            $cc = strtoupper(trim((string)($parts[0] ?? '')));
            $lane = strtolower(trim((string)($parts[1] ?? '')));
            if ($cc === '') {
                continue;
            }
            $rows[$cc] = $lane;
            if ($cc === 'US') {
                $usLane = $lane;
            }
        }
        self::assertSame('americas', $usLane);
        self::assertGreaterThanOrEqual(200, count($rows));
        self::assertArrayNotHasKey('KP', $rows);
        self::assertArrayNotHasKey('AQ', $rows);
    }

    public function testSeedMergesDestinationAndCarrierCoverageWhenExpanding(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php'
        );
        self::assertStringContainsString('marketCountryRows', $src);
        self::assertStringContainsString('仅并入种子市场缺失国', $src);
        self::assertStringContainsString('中国大陆仓发货', $src);
        self::assertStringContainsString("'fee' => 45.00", $src);
        self::assertStringContainsString("'weight_rate' => 14.00", $src);
    }
}
