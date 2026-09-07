<?php
declare(strict_types=1);

/**
 * 构建机：把 GeoNames 邮编写入 address-catalog/{CC}/postal.tsv.gz（非样本）。
 * CN 优先用 build-cn-postal-from-adcode-zip.php（adcode 对齐更好）。
 *
 * GeoNames 列：country postal place admin1name admin1code admin2name admin2code admin3name admin3code lat lng accuracy
 *
 * 用法：
 *   php app/code/Weline/Shipping/scripts/build-global-postal-from-geonames.php
 *   php ... --source-dir=var/tmp/geonames-postal --only=US,DE,FR
 *   php ... --from-all-countries[=allCountries-postal.zip]  # 从全集 zip/txt 拆国后构建
 *   php ... --download-all-countries
 */

ini_set('memory_limit', '1024M');

$repoRoot = dirname(__DIR__, 5);
$shippingDir = dirname(__DIR__);
$catalogRoot = $shippingDir . '/data/address-catalog';
$defaultSource = $repoRoot . '/var/tmp/geonames-postal';

$sourceDir = $defaultSource;
$only = null;
$fromAll = null;
$downloadAll = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--source-dir=')) {
        $sourceDir = substr($arg, 13);
    }
    if (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(array_map(
            static fn(string $v): string => strtoupper(trim($v)),
            explode(',', substr($arg, 7))
        )));
    }
    if ($arg === '--from-all-countries' || str_starts_with($arg, '--from-all-countries=')) {
        $fromAll = $arg === '--from-all-countries'
            ? ($sourceDir . '/allCountries-postal.zip')
            : substr($arg, strlen('--from-all-countries='));
    }
    if ($arg === '--download-all-countries') {
        $downloadAll = true;
    }
}

if (!is_dir($sourceDir)) {
    mkdir($sourceDir, 0755, true);
}

if ($downloadAll) {
    $zipPath = $sourceDir . '/allCountries-postal.zip';
    if (!is_file($zipPath) || filesize($zipPath) < 1000000) {
        $url = 'https://download.geonames.org/export/zip/allCountries.zip';
        fwrite(STDERR, "Downloading {$url}\n");
        $data = @file_get_contents($url);
        if ($data === false || $data === '') {
            fwrite(STDERR, "Failed to download allCountries.zip\n");
            exit(1);
        }
        file_put_contents($zipPath, $data);
    }
    $fromAll = $fromAll ?: $zipPath;
}

if ($fromAll !== null) {
    $allTxt = $sourceDir . '/allCountries.txt';
    if (str_ends_with(strtolower($fromAll), '.zip')) {
        if (!is_file($fromAll)) {
            fwrite(STDERR, "Missing {$fromAll}\n");
            exit(1);
        }
        $zip = new ZipArchive();
        if ($zip->open($fromAll) !== true) {
            fwrite(STDERR, "Cannot open zip {$fromAll}\n");
            exit(1);
        }
        $member = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === 'allCountries.txt' || str_ends_with(strtolower($name), '/allcountries.txt')) {
                $member = $name;
                break;
            }
        }
        if ($member === null) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name !== '' && str_ends_with(strtolower($name), '.txt')) {
                    $member = $name;
                    break;
                }
            }
        }
        if ($member === null) {
            $zip->close();
            fwrite(STDERR, "No .txt member in {$fromAll}\n");
            exit(1);
        }
        $zip->extractTo($sourceDir, [$member]);
        $zip->close();
        $extracted = $sourceDir . '/' . $member;
        if (is_file($extracted) && realpath($extracted) !== realpath($allTxt)) {
            @mkdir(dirname($allTxt), 0755, true);
            rename($extracted, $allTxt);
        }
    } elseif (is_file($fromAll)) {
        if (!is_file($allTxt) || realpath($fromAll) !== realpath($allTxt)) {
            copy($fromAll, $allTxt);
        }
    } else {
        fwrite(STDERR, "Missing all-countries source {$fromAll}\n");
        exit(1);
    }
    if (!is_file($allTxt)) {
        fwrite(STDERR, "allCountries.txt not available after extract\n");
        exit(1);
    }
    // Split into {CC}.txt for existing per-country loop
    fwrite(STDERR, "Splitting allCountries.txt into per-country files...\n");
    $handles = [];
    $fh = fopen($allTxt, 'rb');
    if ($fh === false) {
        fwrite(STDERR, "Cannot read {$allTxt}\n");
        exit(1);
    }
    while (($line = fgets($fh)) !== false) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $cc = strtoupper(substr($line, 0, 2));
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            continue;
        }
        if (!isset($handles[$cc])) {
            $handles[$cc] = fopen($sourceDir . '/' . $cc . '.txt', 'wb');
        }
        if ($handles[$cc] !== false) {
            fwrite($handles[$cc], $line);
        }
    }
    fclose($fh);
    foreach ($handles as $h) {
        if ($h !== false) {
            fclose($h);
        }
    }
    fwrite(STDERR, 'Split countries: ' . count($handles) . "\n");
}

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Source dir missing: {$sourceDir}\nDownload GeoNames country zips into it first.\n");
    exit(1);
}

/** @var list<string> $countries */
$countries = [];
foreach (scandir($catalogRoot) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..' || $entry === 'MANIFEST.json' || $entry === 'countries.tsv.gz') {
        continue;
    }
    if (is_dir($catalogRoot . '/' . $entry) && preg_match('/^[A-Z]{2}$/', $entry)) {
        $countries[] = $entry;
    }
}
sort($countries);
if ($only !== null) {
    $countries = array_values(array_intersect($countries, $only));
}

/**
 * @return array{by_code: array<string,string>, by_name: array<string,string>}
 */
function loadProvinceIndex(string $path): array
{
    $byCode = [];
    $byName = [];
    if (!is_file($path)) {
        return ['by_code' => $byCode, 'by_name' => $byName];
    }
    $fh = gzopen($path, 'rb');
    if ($fh === false) {
        return ['by_code' => $byCode, 'by_name' => $byName];
    }
    $header = null;
    while (($line = gzgets($fh)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if ($header === null) {
            $header = $cols;
            continue;
        }
        $row = @array_combine($header, $cols) ?: [];
        $code = trim((string)($row['region_code'] ?? ''));
        $name = trim((string)($row['region_name'] ?? ''));
        if ($code === '') {
            continue;
        }
        $byCode[strtoupper($code)] = $code;
        // US-AL → AL
        if (preg_match('/^[A-Z]{2}-(.+)$/', strtoupper($code), $m)) {
            $byCode[$m[1]] = $code;
        }
        if ($name !== '') {
            $byName[normalizeName($name)] = $code;
        }
    }
    gzclose($fh);

    return ['by_code' => $byCode, 'by_name' => $byName];
}

/**
 * @return array{by_code: array<string,array{code:string,parent:string}>, by_name: array<string,array{code:string,parent:string}>}
 */
function loadCityIndex(string $path): array
{
    $byCode = [];
    $byName = [];
    if (!is_file($path)) {
        return ['by_code' => $byCode, 'by_name' => $byName];
    }
    $fh = gzopen($path, 'rb');
    if ($fh === false) {
        return ['by_code' => $byCode, 'by_name' => $byName];
    }
    $header = null;
    while (($line = gzgets($fh)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if ($header === null) {
            $header = $cols;
            continue;
        }
        $row = @array_combine($header, $cols) ?: [];
        $code = trim((string)($row['region_code'] ?? ''));
        $parent = trim((string)($row['parent_code'] ?? ''));
        $name = trim((string)($row['region_name'] ?? ''));
        if ($code === '') {
            continue;
        }
        $meta = ['code' => $code, 'parent' => $parent];
        $byCode[strtoupper($code)] = $meta;
        if ($name !== '') {
            $key = normalizeName($name);
            // keep first; name collisions exist
            if (!isset($byName[$key])) {
                $byName[$key] = $meta;
            }
            if ($parent !== '') {
                $byName[$key . '|' . strtoupper($parent)] = $meta;
            }
        }
    }
    gzclose($fh);

    return ['by_code' => $byCode, 'by_name' => $byName];
}

function normalizeName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, [
        'shi' => '',
        ' province' => '',
        ' district' => '',
        ' county' => '',
        ' city' => '',
        ' pref' => '',
        ' ken' => '',
        ' to' => '',
        ' fu' => '',
        '州' => '',
        '省' => '',
        '市' => '',
        '区' => '',
        '县' => '',
    ]);
    $name = preg_replace('/[^a-z0-9\p{Han}]+/u', '', $name) ?? $name;

    return $name;
}

function writePostalTsvGz(string $path, array $rows): void
{
    @mkdir(dirname($path), 0775, true);
    $fh = gzopen($path, 'wb9');
    if ($fh === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    gzwrite($fh, "country_code\tpostal_code\tpostal_code_norm\tplace_name\tprovince_code\tcity_code\tdistrict_code\n");
    foreach ($rows as $row) {
        gzwrite($fh, implode("\t", [
            $row['country_code'],
            $row['postal_code'],
            $row['postal_code_norm'],
            $row['place_name'],
            $row['province_code'],
            $row['city_code'],
            $row['district_code'],
        ]) . "\n");
    }
    gzclose($fh);
}

$summary = [];
foreach ($countries as $cc) {
    if ($cc === 'CN') {
        // CN 由专用脚本维护，避免被粗粒度 GeoNames 覆盖
        $existing = $catalogRoot . '/CN/postal.tsv.gz';
        $n = 0;
        if (is_file($existing)) {
            $fh = gzopen($existing, 'rb');
            if ($fh !== false) {
                while (gzgets($fh) !== false) {
                    $n++;
                }
                gzclose($fh);
                $n = max(0, $n - 1);
            }
        }
        $summary[$cc] = ['rows' => $n, 'source' => 'adcode-zip-kept'];
        echo "SKIP CN (keep adcode-zip postal, rows={$n})\n";
        continue;
    }

    $src = $sourceDir . '/' . $cc . '.txt';
    if (!is_file($src)) {
        $summary[$cc] = ['rows' => 0, 'source' => 'missing'];
        echo "MISS {$cc} (no {$src})\n";
        continue;
    }

    $provinces = loadProvinceIndex($catalogRoot . '/' . $cc . '/provinces.tsv.gz');
    $cities = loadCityIndex($catalogRoot . '/' . $cc . '/cities.tsv.gz');

    $outPath = $catalogRoot . '/' . $cc . '/postal.tsv.gz';
    @mkdir(dirname($outPath), 0775, true);
    $outFh = gzopen($outPath, 'wb9');
    if ($outFh === false) {
        echo "FAIL write {$outPath}\n";
        continue;
    }
    gzwrite($outFh, "country_code\tpostal_code\tpostal_code_norm\tplace_name\tprovince_code\tcity_code\tdistrict_code\n");

    $seen = [];
    $count = 0;
    $linked = 0;
    $fh = fopen($src, 'rb');
    if ($fh === false) {
        gzclose($outFh);
        echo "FAIL open {$src}\n";
        continue;
    }
    while (($line = fgets($fh)) !== false) {
        $cols = explode("\t", rtrim($line, "\r\n"));
        if (count($cols) < 3) {
            continue;
        }
        $postal = trim((string)$cols[1]);
        $place = trim((string)$cols[2]);
        $admin1Name = trim((string)($cols[3] ?? ''));
        $admin1Code = trim((string)($cols[4] ?? ''));
        $admin2Name = trim((string)($cols[5] ?? ''));
        $admin2Code = trim((string)($cols[6] ?? ''));
        if ($postal === '') {
            continue;
        }
        $norm = strtoupper(preg_replace('/\s+/', '', $postal) ?? $postal);

        $provinceCode = '';
        if ($admin1Code !== '') {
            $provinceCode = $provinces['by_code'][strtoupper($admin1Code)]
                ?? $provinces['by_code'][strtoupper($cc . '-' . $admin1Code)]
                ?? '';
        }
        if ($provinceCode === '' && $admin1Name !== '') {
            $provinceCode = $provinces['by_name'][normalizeName($admin1Name)] ?? '';
        }

        $cityCode = '';
        if ($admin2Code !== '') {
            $hit = $cities['by_code'][strtoupper($admin2Code)] ?? null;
            if ($hit) {
                $cityCode = $hit['code'];
                if ($provinceCode === '' && $hit['parent'] !== '') {
                    $provinceCode = $hit['parent'];
                }
            }
        }
        if ($cityCode === '' && $admin2Name !== '') {
            $n = normalizeName($admin2Name);
            $hit = null;
            if ($provinceCode !== '') {
                $hit = $cities['by_name'][$n . '|' . strtoupper($provinceCode)] ?? null;
            }
            $hit = $hit ?: ($cities['by_name'][$n] ?? null);
            if ($hit) {
                $cityCode = $hit['code'];
                if ($provinceCode === '' && $hit['parent'] !== '') {
                    $provinceCode = $hit['parent'];
                }
            }
        }
        // fallback: place name as city
        if ($cityCode === '' && $place !== '') {
            $n = normalizeName($place);
            $hit = null;
            if ($provinceCode !== '') {
                $hit = $cities['by_name'][$n . '|' . strtoupper($provinceCode)] ?? null;
            }
            $hit = $hit ?: ($cities['by_name'][$n] ?? null);
            if ($hit) {
                $cityCode = $hit['code'];
                if ($provinceCode === '' && $hit['parent'] !== '') {
                    $provinceCode = $hit['parent'];
                }
            }
        }

        $placeName = $place !== '' ? $place : $postal;
        $key = $norm . '|' . $placeName . '|' . $provinceCode . '|' . $cityCode;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        gzwrite($outFh, implode("\t", [
            $cc,
            str_replace(["\t", "\n", "\r"], ' ', $postal),
            str_replace(["\t", "\n", "\r"], ' ', $norm),
            str_replace(["\t", "\n", "\r"], ' ', $placeName),
            $provinceCode,
            $cityCode,
            '',
        ]) . "\n");
        $count++;
        if ($provinceCode !== '' || $cityCode !== '') {
            $linked++;
        }
    }
    fclose($fh);
    gzclose($outFh);
    unset($seen);

    $summary[$cc] = ['rows' => $count, 'linked' => $linked, 'source' => 'geonames'];
    echo "OK {$cc} rows={$count} linked={$linked} → {$outPath}\n";
}

$manifestPath = $catalogRoot . '/MANIFEST.json';
$manifest = is_file($manifestPath) ? (json_decode((string)file_get_contents($manifestPath), true) ?: []) : [];
$manifest['postal_built_at'] = gmdate('c');
$manifest['postal_sources'] = [
    'CN' => 'tombcato/china-zipcode-data (adcode↔zip)',
    'others' => 'GeoNames export/zip/{CC}.zip',
];
$manifest['postal_summary'] = $summary;
file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "Done countries=" . count($summary) . "\n";
