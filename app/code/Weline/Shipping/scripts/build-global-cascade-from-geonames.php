<?php
declare(strict_types=1);

/**
 * 构建机：GeoNames ADM1/ADM2 → address-catalog/{CC}/provinces|cities.tsv.gz
 *
 * 默认 --fill-missing：只补尚未有 provinces.tsv.gz 的国家（保留 CN/G20 等已有包）。
 * CN 始终跳过（modood）。
 * 无 GeoNames ADM1 的国家/领地：写入合成省 `{CC}-00`（Nationwide / 国名），保证级联可选。
 * 无 GeoNames ADM2 且 cities 为空：`--fill-empty-cities` 按省合成 `{prov}-CITY`（同名），保证市可选。
 *
 * 用法：
 *   php app/code/Weline/Shipping/scripts/build-global-cascade-from-geonames.php
 *   php ... --source-dir=var/tmp/geonames-cascade --only=EG,PY
 *   php ... --force          # 覆盖已有（仍跳过 --keep）
 *   php ... --keep=CN,HK,MO,TW
 *   php ... --download       # 缺文件时从 download.geonames.org 拉取
 *   php ... --no-synthetic   # 无 ADM1 时跳过（默认会合成）
 *   php ... --fill-empty-cities [--only=SG,QA]  # 仅补空 cities（不覆盖已有行）
 */

$repoRoot = dirname(__DIR__, 5);
$shippingDir = dirname(__DIR__);
$catalogRoot = $shippingDir . '/data/address-catalog';
$defaultSource = $repoRoot . '/var/tmp/geonames-cascade';

$sourceDir = $defaultSource;
$only = null;
$force = false;
$fillMissing = true;
$fillEmptyCities = false;
$download = false;
$synthetic = true;
$keep = ['CN'];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--source-dir=')) {
        $sourceDir = substr($arg, 13);
    } elseif (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(array_map(
            static fn(string $v): string => strtoupper(trim($v)),
            explode(',', substr($arg, 7))
        )));
        $fillMissing = false;
    } elseif ($arg === '--force') {
        $force = true;
        $fillMissing = false;
    } elseif ($arg === '--fill-missing') {
        $fillMissing = true;
    } elseif ($arg === '--fill-empty-cities') {
        $fillEmptyCities = true;
    } elseif ($arg === '--download') {
        $download = true;
    } elseif ($arg === '--no-synthetic') {
        $synthetic = false;
    } elseif (str_starts_with($arg, '--keep=')) {
        $keep = array_values(array_filter(array_map(
            static fn(string $v): string => strtoupper(trim($v)),
            explode(',', substr($arg, 7))
        )));
    }
}

if (!is_dir($sourceDir)) {
    mkdir($sourceDir, 0755, true);
}

$onlyFillEmptyCities = $fillEmptyCities
    && !$force
    && !in_array('--fill-missing', $argv, true)
    && $only === null;

$admin1Path = $sourceDir . '/admin1CodesASCII.txt';
$admin2Path = $sourceDir . '/admin2Codes.txt';

$header = ['country_code', 'region_code', 'region_name', 'parent_code', 'sort_order', 'postal_code'];
$built = [];
$skipped = [];
$syntheticCities = [];

/**
 * @return list<array{country_code:string,region_code:string,region_name:string,parent_code:string,sort_order:int,postal_code:string}>
 */
function readProvinceRows(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fh = gzopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    $hdr = null;
    $rows = [];
    while (($line = gzgets($fh)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if ($hdr === null) {
            $hdr = $cols;
            continue;
        }
        $row = @array_combine($hdr, $cols) ?: [];
        $code = trim((string)($row['region_code'] ?? ''));
        $name = trim((string)($row['region_name'] ?? ''));
        if ($code === '' || $name === '') {
            continue;
        }
        $rows[] = [
            'country_code' => strtoupper(trim((string)($row['country_code'] ?? ''))),
            'region_code' => $code,
            'region_name' => $name,
            'parent_code' => trim((string)($row['parent_code'] ?? '')),
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'postal_code' => trim((string)($row['postal_code'] ?? '')),
        ];
    }
    gzclose($fh);

    return $rows;
}

function cityDataRowCount(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $fh = gzopen($path, 'rb');
    if ($fh === false) {
        return 0;
    }
    $n = 0;
    $first = true;
    while (gzgets($fh) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        $n++;
    }
    gzclose($fh);

    return $n;
}

/**
 * @param list<string>|null $only
 * @param list<string> $keep
 * @return array<string, array{provinces:int,cities:int,source:string}>
 */
function fillEmptyCities(string $catalogRoot, array $header, ?array $only, array $keep): array
{
    $out = [];
    $targets = [];
    if ($only !== null) {
        $targets = $only;
    } else {
        foreach (scandir($catalogRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match('/^[A-Z]{2}$/', $entry)) {
                continue;
            }
            $targets[] = $entry;
        }
        sort($targets);
    }

    foreach ($targets as $cc) {
        if (in_array($cc, $keep, true)) {
            continue;
        }
        $provPath = $catalogRoot . '/' . $cc . '/provinces.tsv.gz';
        $cityPath = $catalogRoot . '/' . $cc . '/cities.tsv.gz';
        if (!is_file($provPath)) {
            continue;
        }
        if (cityDataRowCount($cityPath) > 0) {
            continue;
        }
        $provinces = readProvinceRows($provPath);
        if ($provinces === []) {
            continue;
        }
        $cityRows = [];
        foreach ($provinces as $i => $p) {
            $cityRows[] = [
                'country_code' => $cc,
                'region_code' => $p['region_code'] . '-CITY',
                'region_name' => $p['region_name'],
                'parent_code' => $p['region_code'],
                'sort_order' => $i,
                'postal_code' => '',
            ];
        }
        writeTsvGz($cityPath, $header, $cityRows);
        $out[$cc] = [
            'provinces' => count($provinces),
            'cities' => count($cityRows),
            'source' => 'synthetic-city-per-province',
        ];
        fwrite(STDOUT, sprintf(
            "%s synthetic-cities=%d (from %d provinces)\n",
            $cc,
            count($cityRows),
            count($provinces)
        ));
    }

    return $out;
}

if ($onlyFillEmptyCities) {
    $syntheticCities = fillEmptyCities($catalogRoot, $header, $only, $keep);
    $manifestPath = $catalogRoot . '/MANIFEST.json';
    $manifest = is_file($manifestPath)
        ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
        : [];
    $manifest['schema_version'] = $manifest['schema_version'] ?? 'address-catalog.v1';
    $manifest['synthetic_cities_built_at'] = gmdate('c');
    $manifest['synthetic_cities_source'] = 'province-mirror-CITY';
    if (!isset($manifest['countries_with_subnational']) || !is_array($manifest['countries_with_subnational'])) {
        $manifest['countries_with_subnational'] = [];
    }
    foreach ($syntheticCities as $cc => $stats) {
        $prev = $manifest['countries_with_subnational'][$cc] ?? [];
        $manifest['countries_with_subnational'][$cc] = [
            'provinces' => $stats['provinces'],
            'cities' => $stats['cities'],
            'districts' => (int)($prev['districts'] ?? 0),
            'postal' => (int)($prev['postal'] ?? 0),
            'source_pack' => 'geonames-synthetic-city',
            'pack_version' => 'synthetic-city-per-province-v1',
        ];
    }
    ksort($manifest['countries_with_subnational']);
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
    fwrite(STDOUT, json_encode([
        'mode' => 'fill-empty-cities',
        'built' => count($syntheticCities),
        'built_countries' => array_keys($syntheticCities),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
}

if ($download || !is_file($admin1Path) || !is_file($admin2Path)) {
    foreach (['admin1CodesASCII.txt', 'admin2Codes.txt'] as $file) {
        $dest = $sourceDir . '/' . $file;
        if (is_file($dest) && filesize($dest) > 0) {
            continue;
        }
        $url = 'https://download.geonames.org/export/dump/' . $file;
        fwrite(STDERR, "Downloading {$url}\n");
        $data = @file_get_contents($url);
        if ($data === false || $data === '') {
            fwrite(STDERR, "Failed to download {$file}\n");
            exit(1);
        }
        file_put_contents($dest, $data);
    }
}

if (!is_file($admin1Path) || !is_file($admin2Path)) {
    fwrite(STDERR, "Missing GeoNames dumps in {$sourceDir}\n");
    exit(1);
}

/**
 * @return array<string, list<array{code:string,name:string,ascii:string,geonameid:string}>>
 */
function loadAdmin1(string $path): array
{
    $byCc = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) < 4) {
            continue;
        }
        $full = trim($cols[0]);
        if (!preg_match('/^([A-Z]{2})\.(.+)$/', $full, $m)) {
            continue;
        }
        $cc = $m[1];
        $local = $m[2];
        $name = trim($cols[1]);
        $ascii = trim($cols[2]);
        $gid = trim($cols[3]);
        $display = $ascii !== '' ? $ascii : $name;
        if ($display === '' || $local === '') {
            continue;
        }
        $byCc[$cc][] = [
            'code' => $cc . '-' . str_replace('.', '-', $local),
            'name' => $display,
            'ascii' => $ascii,
            'geonameid' => $gid,
            'admin1' => $local,
        ];
    }
    fclose($fh);

    return $byCc;
}

/**
 * @return array<string, list<array{code:string,name:string,parent:string,geonameid:string}>>
 */
function loadAdmin2(string $path): array
{
    $byCc = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) < 4) {
            continue;
        }
        $full = trim($cols[0]);
        // CC.ADM1.ADM2
        if (!preg_match('/^([A-Z]{2})\.([^.]+)\.(.+)$/', $full, $m)) {
            continue;
        }
        $cc = $m[1];
        $a1 = $m[2];
        $name = trim($cols[1]);
        $ascii = trim($cols[2]);
        $gid = trim($cols[3]);
        $display = $ascii !== '' ? $ascii : $name;
        if ($display === '' || $gid === '') {
            continue;
        }
        $parent = $cc . '-' . str_replace('.', '-', $a1);
        $byCc[$cc][] = [
            'code' => $cc . '-C' . $gid,
            'name' => $display,
            'parent' => $parent,
            'geonameid' => $gid,
        ];
    }
    fclose($fh);

    return $byCc;
}

/**
 * @param list<array<string, string|int>> $rows
 */
function writeTsvGz(string $path, array $header, array $rows): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fh = gzopen($path, 'wb9');
    if ($fh === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    gzwrite($fh, implode("\t", $header) . "\n");
    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $col) {
            $line[] = str_replace(["\t", "\n", "\r"], ' ', (string)($row[$col] ?? ''));
        }
        gzwrite($fh, implode("\t", $line) . "\n");
    }
    gzclose($fh);
}

$admin1 = loadAdmin1($admin1Path);
$admin2 = loadAdmin2($admin2Path);

/** @var array<string, string> $countryNames */
$countryNames = [];
$countriesPath = $catalogRoot . '/countries.tsv.gz';
if (is_file($countriesPath)) {
    $fh = gzopen($countriesPath, 'rb');
    if ($fh !== false) {
        $hdr = null;
        while (($line = gzgets($fh)) !== false) {
            $cols = explode("\t", rtrim($line, "\r\n"));
            if ($hdr === null) {
                $hdr = $cols;
                continue;
            }
            $row = @array_combine($hdr, $cols) ?: [];
            $code = strtoupper(trim((string)($row['country_code'] ?? $row['region_code'] ?? '')));
            $name = trim((string)($row['region_name'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code) && $name !== '') {
                $countryNames[$code] = $name;
            }
        }
        gzclose($fh);
    }
}

/** @var list<string> $targets */
$targets = array_values(array_unique(array_merge(
    array_keys($admin1),
    array_keys($admin2),
    array_keys($countryNames)
)));
sort($targets);

if ($only !== null) {
    $targets = array_values(array_intersect($targets, $only));
}

$header = ['country_code', 'region_code', 'region_name', 'parent_code', 'sort_order', 'postal_code'];
$built = [];
$skipped = [];

foreach ($targets as $cc) {
    if (in_array($cc, $keep, true)) {
        $skipped[$cc] = 'keep';
        continue;
    }
    $provPath = $catalogRoot . '/' . $cc . '/provinces.tsv.gz';
    if ($fillMissing && is_file($provPath) && !$force) {
        $skipped[$cc] = 'exists';
        continue;
    }
    if (!$fillMissing && !$force && $only === null && is_file($provPath)) {
        $skipped[$cc] = 'exists';
        continue;
    }

    $provinces = $admin1[$cc] ?? [];
    $cities = $admin2[$cc] ?? [];
    $source = 'geonames-admin1-admin2';

    // 无 ADM1：合成全国省，保证选择器有下级入口（SG/GI 等城邦/领地）
    if ($provinces === []) {
        if (!$synthetic) {
            $skipped[$cc] = 'no_admin1';
            continue;
        }
        $label = $countryNames[$cc] ?? 'Nationwide';
        $provinces = [[
            'code' => $cc . '-00',
            'name' => $label,
            'ascii' => $label,
            'geonameid' => '',
            'admin1' => '00',
        ]];
        $source = 'geonames-synthetic-nationwide';
    }

    usort($provinces, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $provCodes = [];
    $provRows = [];
    foreach ($provinces as $i => $p) {
        $provCodes[$p['code']] = true;
        $provRows[] = [
            'country_code' => $cc,
            'region_code' => $p['code'],
            'region_name' => $p['name'],
            'parent_code' => $cc,
            'sort_order' => $i,
            'postal_code' => '',
        ];
    }

    usort($cities, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $cityRows = [];
    $orphans = 0;
    foreach ($cities as $i => $c) {
        if (!isset($provCodes[$c['parent']])) {
            // 合成全国省时，把孤儿 ADM2 挂到 {CC}-00
            if (isset($provCodes[$cc . '-00'])) {
                $c['parent'] = $cc . '-00';
            } else {
                $orphans++;
                continue;
            }
        }
        $cityRows[] = [
            'country_code' => $cc,
            'region_code' => $c['code'],
            'region_name' => $c['name'],
            'parent_code' => $c['parent'],
            'sort_order' => $i,
            'postal_code' => '',
        ];
    }

    writeTsvGz($provPath, $header, $provRows);
    writeTsvGz($catalogRoot . '/' . $cc . '/cities.tsv.gz', $header, $cityRows);

    $built[$cc] = [
        'provinces' => count($provRows),
        'cities' => count($cityRows),
        'districts' => 0,
        'orphaned_admin2' => $orphans,
        'source' => $source,
    ];
    fwrite(STDOUT, sprintf(
        "%s provinces=%d cities=%d orphans=%d source=%s\n",
        $cc,
        count($provRows),
        count($cityRows),
        $orphans,
        $source
    ));
}

$manifestPath = $catalogRoot . '/MANIFEST.json';
$manifest = [];
if (is_file($manifestPath)) {
    $manifest = json_decode((string)file_get_contents($manifestPath), true) ?: [];
}
$manifest['schema_version'] = $manifest['schema_version'] ?? 'address-catalog.v1';
$manifest['pack_version'] = 'address-catalog-geonames-cascade-v1';
$manifest['cascade_built_at'] = gmdate('c');
$manifest['cascade_source'] = [
    'fill' => 'geonames admin1CodesASCII + admin2Codes',
    'kept' => $keep,
    'mode' => $force ? 'force' : ($only !== null ? 'only' : 'fill-missing'),
];
if (!isset($manifest['countries_with_subnational']) || !is_array($manifest['countries_with_subnational'])) {
    $manifest['countries_with_subnational'] = [];
}
foreach ($built as $cc => $stats) {
    $manifest['countries_with_subnational'][$cc] = [
        'provinces' => $stats['provinces'],
        'cities' => $stats['cities'],
        'districts' => 0,
        'postal' => $manifest['countries_with_subnational'][$cc]['postal'] ?? 0,
        'source_pack' => str_starts_with((string)($stats['source'] ?? ''), 'geonames-synthetic') ? 'geonames-synthetic' : 'geonames-admin',
        'pack_version' => (string)($stats['source'] ?? 'geonames-admin1-admin2-v1'),
    ];
}

if ($fillEmptyCities) {
    $syntheticCities = fillEmptyCities($catalogRoot, $header, $only, $keep);
    foreach ($syntheticCities as $cc => $stats) {
        $prev = $manifest['countries_with_subnational'][$cc] ?? [];
        $manifest['countries_with_subnational'][$cc] = [
            'provinces' => $stats['provinces'],
            'cities' => $stats['cities'],
            'districts' => (int)($prev['districts'] ?? 0),
            'postal' => (int)($prev['postal'] ?? 0),
            'source_pack' => 'geonames-synthetic-city',
            'pack_version' => 'synthetic-city-per-province-v1',
        ];
        $built[$cc] = $stats;
    }
    $manifest['synthetic_cities_built_at'] = gmdate('c');
}

ksort($manifest['countries_with_subnational']);
file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

$summary = [
    'built' => count($built),
    'skipped' => count($skipped),
    'built_countries' => array_keys($built),
    'synthetic_cities' => count($syntheticCities),
    'sample_skipped' => array_slice($skipped, 0, 20, true),
];
fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
