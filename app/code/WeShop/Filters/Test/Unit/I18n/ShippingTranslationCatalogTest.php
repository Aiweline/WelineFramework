<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

class ShippingTranslationCatalogTest extends TestCase
{
    public function testShippingTermsAreConsistentAcrossLocales(): void
    {
        $moduleRoot = dirname(__DIR__, 3);

        $enMap = $this->readCsvMap($moduleRoot . '/i18n/en_US.csv');
        $zhHansMap = $this->readCsvMap($moduleRoot . '/i18n/zh_Hans_CN.csv');
        $zhCnMap = $this->readCsvMap($moduleRoot . '/i18n/zh_CN.csv');

        $this->assertSame('Shipping', $enMap['配送方式'] ?? null);
        $this->assertSame('Free Shipping', $enMap['免运费'] ?? null);
        $this->assertSame('Same Day Delivery', $enMap['当日达'] ?? null);
        $this->assertSame('Next Day Delivery', $enMap['次日达'] ?? null);
        $this->assertSame('Express Delivery', $enMap['快递'] ?? null);
        $this->assertSame('Save failed: %1', $enMap['保存失败: %1'] ?? null);
        $this->assertSame('Clear cache failed: %1', $enMap['清除缓存失败: %1'] ?? null);

        $this->assertSame('配送方式', $zhHansMap['Shipping'] ?? null);
        $this->assertSame('免运费', $zhHansMap['Free Shipping'] ?? null);
        $this->assertSame('当日达', $zhHansMap['Same Day Delivery'] ?? null);
        $this->assertSame('次日达', $zhHansMap['Next Day Delivery'] ?? null);
        $this->assertSame('快递', $zhHansMap['Express Delivery'] ?? null);

        $this->assertSame('配送方式', $zhCnMap['Shipping'] ?? null);
        $this->assertSame('免运费', $zhCnMap['Free Shipping'] ?? null);
        $this->assertSame('当日达', $zhCnMap['Same Day Delivery'] ?? null);
        $this->assertSame('次日达', $zhCnMap['Next Day Delivery'] ?? null);
        $this->assertSame('快递', $zhCnMap['Express Delivery'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function readCsvMap(string $path): array
    {
        $content = (string) file_get_contents($path);
        $lines = preg_split('/\R/u', $content) ?: [];

        $map = [];
        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line);
            if (count($columns) < 2) {
                continue;
            }

            $key = $columns[0];
            if ($index === 0) {
                $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
            }
            $map[$key] = (string) $columns[1];
        }

        return $map;
    }
}
