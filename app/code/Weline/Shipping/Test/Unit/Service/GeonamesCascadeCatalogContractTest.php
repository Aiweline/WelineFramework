<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * GeoNames 全球级联 fill-missing 后，缺省国应有 provinces/cities TSV。
 */
final class GeonamesCascadeCatalogContractTest extends TestCase
{
    private function catalogRoot(): string
    {
        return dirname(__DIR__, 3) . '/data/address-catalog';
    }

    public function testBuildScriptExists(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/build-global-cascade-from-geonames.php';
        self::assertFileExists($script);
        $src = (string)file_get_contents($script);
        self::assertStringContainsString('--fill-missing', $src);
        self::assertStringContainsString('--fill-empty-cities', $src);
        self::assertStringContainsString('admin1CodesASCII', $src);
    }

    public function testEgyptAndParaguayHaveProvinceAndCityLayers(): void
    {
        foreach (['EG', 'PY'] as $cc) {
            $prov = $this->catalogRoot() . '/' . $cc . '/provinces.tsv.gz';
            $city = $this->catalogRoot() . '/' . $cc . '/cities.tsv.gz';
            self::assertFileExists($prov, $cc . ' provinces');
            self::assertFileExists($city, $cc . ' cities');
            $provLines = $this->gzLineCount($prov);
            $cityLines = $this->gzLineCount($city);
            self::assertGreaterThan(5, $provLines, $cc . ' provinces rows');
            self::assertGreaterThan(10, $cityLines, $cc . ' cities rows');
        }
    }

    public function testCatalogProvinceCoverageIsNearGlobal(): void
    {
        $root = $this->catalogRoot();
        $dirs = glob($root . '/*/provinces.tsv.gz') ?: [];
        self::assertGreaterThanOrEqual(249, count($dirs), 'expect all countries.tsv entries to have provinces (incl. synthetic)');
    }

    public function testCatalogCityCoverageIsGlobalWhenProvincesExist(): void
    {
        $root = $this->catalogRoot();
        $empty = [];
        foreach (glob($root . '/*/provinces.tsv.gz') ?: [] as $prov) {
            $cc = basename(dirname($prov));
            $city = $root . '/' . $cc . '/cities.tsv.gz';
            if (!is_file($city) || $this->gzLineCount($city) <= 0) {
                $empty[] = $cc;
            }
        }
        self::assertSame([], $empty, 'every country with provinces must have at least one city row (ADM2 or synthetic)');
    }

    public function testSyntheticNationwideExistsForCityStates(): void
    {
        foreach (['SG', 'GI', 'VA'] as $cc) {
            $path = $this->catalogRoot() . '/' . $cc . '/provinces.tsv.gz';
            self::assertFileExists($path);
            $fh = gzopen($path, 'rb');
            self::assertNotFalse($fh);
            $body = stream_get_contents($fh);
            gzclose($fh);
            self::assertStringContainsString($cc . '-00', (string)$body);

            $cityPath = $this->catalogRoot() . '/' . $cc . '/cities.tsv.gz';
            self::assertGreaterThan(0, $this->gzLineCount($cityPath), $cc . ' synthetic city');
            $cfh = gzopen($cityPath, 'rb');
            self::assertNotFalse($cfh);
            $cityBody = (string)stream_get_contents($cfh);
            gzclose($cfh);
            self::assertStringContainsString($cc . '-00-CITY', $cityBody);
        }
    }

    public function testAfghanistanBadakhshanHasCitiesInCatalog(): void
    {
        $city = $this->catalogRoot() . '/AF/cities.tsv.gz';
        self::assertFileExists($city);
        $fh = gzopen($city, 'rb');
        self::assertNotFalse($fh);
        $body = (string)stream_get_contents($fh);
        gzclose($fh);
        self::assertStringContainsString("\tAF-01\t", $body);
        self::assertStringContainsString('Faizabad', $body);
    }

    private function gzLineCount(string $path): int
    {
        $fh = gzopen($path, 'rb');
        self::assertNotFalse($fh);
        $n = 0;
        while (gzgets($fh) !== false) {
            $n++;
        }
        gzclose($fh);

        return max(0, $n - 1);
    }
}
