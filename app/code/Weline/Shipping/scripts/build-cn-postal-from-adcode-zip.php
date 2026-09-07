<?php
declare(strict_types=1);

/**
 * 构建机：从 china-zipcode-data（adcode↔zipCode）生成 CN/postal.tsv.gz。
 * 源：https://cdn.jsdelivr.net/gh/tombcato/china-zipcode-data@latest/china_zipcode_adcode.json
 *
 * 用法：
 *   php app/code/Weline/Shipping/scripts/build-cn-postal-from-adcode-zip.php
 *   php ... --from=/path/china_zipcode_adcode.json
 */

$shippingDir = dirname(__DIR__);
$outPath = $shippingDir . '/data/address-catalog/CN/postal.tsv.gz';
$defaultUrl = 'https://cdn.jsdelivr.net/gh/tombcato/china-zipcode-data@latest/china_zipcode_adcode.json';

$from = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--from=')) {
        $from = substr($arg, 7);
    }
}

if ($from !== null && $from !== '') {
    if (!is_file($from)) {
        fwrite(STDERR, "Source file not found: {$from}\n");
        exit(1);
    }
    $json = file_get_contents($from);
} else {
    $cache = dirname(__DIR__, 4) . '/var/tmp/geonames-postal/china_zipcode_adcode.json';
    if (is_file($cache) && filesize($cache) > 1000) {
        $json = file_get_contents($cache);
        echo "Using cache {$cache}\n";
    } else {
        echo "Downloading {$defaultUrl}\n";
        $json = @file_get_contents($defaultUrl);
        if ($json === false || $json === '') {
            fwrite(STDERR, "Download failed. Pass --from=/path/to/china_zipcode_adcode.json\n");
            exit(1);
        }
        @mkdir(dirname($cache), 0775, true);
        file_put_contents($cache, $json);
    }
}

/** @var list<array<string,mixed>>|null $rows */
$rows = json_decode((string)$json, true);
if (!is_array($rows)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$out = [];
$seen = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $districtCode = trim((string)($row['code'] ?? ''));
    $postal = trim((string)($row['zipCode'] ?? $row['zipcode'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $cityCode = trim((string)($row['cityCode'] ?? ''));
    $provinceCode = trim((string)($row['provinceCode'] ?? ''));
    if ($districtCode === '' || $postal === '' || !preg_match('/^\d{6}$/', $postal)) {
        continue;
    }
    // 仅挂县级/区级 6 位 adcode（与 catalog districts.region_code 对齐）
    if (!preg_match('/^\d{6}$/', $districtCode)) {
        continue;
    }
    $norm = $postal;
    $key = $norm . '|' . $districtCode;
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $out[] = [
        'country_code' => 'CN',
        'postal_code' => $postal,
        'postal_code_norm' => $norm,
        'place_name' => $name !== '' ? $name : $postal,
        'province_code' => $provinceCode,
        'city_code' => $cityCode,
        'district_code' => $districtCode,
    ];
}

usort($out, static function (array $a, array $b): int {
    return [$a['postal_code_norm'], $a['district_code']] <=> [$b['postal_code_norm'], $b['district_code']];
});

@mkdir(dirname($outPath), 0775, true);
$fh = gzopen($outPath, 'wb9');
if ($fh === false) {
    fwrite(STDERR, "Cannot write {$outPath}\n");
    exit(1);
}
gzwrite($fh, "country_code\tpostal_code\tpostal_code_norm\tplace_name\tprovince_code\tcity_code\tdistrict_code\n");
foreach ($out as $row) {
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

$has610500 = false;
foreach ($out as $row) {
    if ($row['postal_code'] === '610500' && $row['district_code'] === '510114') {
        $has610500 = true;
        break;
    }
}

echo 'Wrote ' . count($out) . " postal rows → {$outPath}\n";
echo '610500→510114 ' . ($has610500 ? 'OK' : 'MISSING') . "\n";
