<?php

declare(strict_types=1);

/**
 * Scan all product detail images for baked Chinese parameter/size charts,
 * OCR with tesseract, and replace with semantic HTML for i18n.
 *
 * php app/code/Weline/Product/scripts/batch-remediate-detail-baked-text.php --scan
 * php app/code/Weline/Product/scripts/batch-remediate-detail-baked-text.php --apply
 * php app/code/Weline/Product/scripts/batch-remediate-detail-baked-text.php --scan --apply --limit=20
 * Optional: --website=0 --product=175 --workers=8 --scan-file=...
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\DetailTextifiedAssetPurger;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', [
    'scan', 'apply', 'dry-run', 'website:', 'product:', 'limit:', 'workers:', 'scan-file:', 'min-white:',
]);
$doScan = isset($options['scan']) || (!isset($options['apply']) && !isset($options['dry-run']));
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productFilter = isset($options['product']) ? max(0, (int)$options['product']) : 0;
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
$workers = max(1, min(16, (int)($options['workers'] ?? 8)));
$minWhite = isset($options['min-white']) ? (float)$options['min-white'] : 35.0;
$root = dirname(__DIR__, 5);
$artifactDir = $root . '/var/hanfu-1688/detail-textify';
@mkdir($artifactDir, 0777, true);
$scanFile = (string)($options['scan-file'] ?? ($artifactDir . '/batch-text-heavy-scan.json'));

$env = include $root . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '5432'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$scan = [
    'generated_at' => date('c'),
    'website_id' => $websiteId,
    'min_white' => $minWhite,
    'assets' => [],
    'stats' => [],
];

if ($doScan || !is_file($scanFile)) {
    fwrite(STDERR, "Collecting description assets...\n");
    $sql = "SELECT entity_id, value_text
            FROM w_product_ws_{$websiteId}_attribute_value
            WHERE entity_type = 'product' AND attribute_code = 'description'
              AND locale = 'zh_Hans_CN'
              AND value_text ILIKE '%asset://%'";
    if ($productFilter > 0) {
        $sql .= ' AND entity_id = ' . $productFilter;
    }
    $sql .= ' ORDER BY entity_id';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    /** @var array<string, array{asset_id:string,products:list<int>,object_key?:string,path?:string}> $assetMeta */
    $assetMeta = [];
    foreach ($rows as $row) {
        $productId = (int)$row['entity_id'];
        if (preg_match_all('/asset:\/\/([a-f0-9-]{36})/i', (string)$row['value_text'], $matches) < 1) {
            continue;
        }
        foreach ($matches[1] as $assetId) {
            $assetId = strtolower($assetId);
            if (!isset($assetMeta[$assetId])) {
                $assetMeta[$assetId] = ['asset_id' => $assetId, 'products' => []];
            }
            $assetMeta[$assetId]['products'][$productId] = $productId;
        }
    }
    foreach ($assetMeta as &$meta) {
        $meta['products'] = array_values($meta['products']);
    }
    unset($meta);

    $ids = array_keys($assetMeta);
    fwrite(STDERR, 'Assets to inspect: ' . count($ids) . "\n");
    foreach (array_chunk($ids, 300) as $chunk) {
        $in = implode(',', array_map(static fn(string $id): string => $pdo->quote($id), $chunk));
        $q = $pdo->query("SELECT asset_id, object_key FROM w_weline_file_asset WHERE asset_id IN ({$in})");
        while ($file = $q->fetch(PDO::FETCH_ASSOC)) {
            $aid = strtolower((string)$file['asset_id']);
            $objectKey = (string)$file['object_key'];
            $path = $root . '/pub/media/' . ltrim($objectKey, '/');
            $assetMeta[$aid]['object_key'] = $objectKey;
            $assetMeta[$aid]['path'] = $path;
        }
    }

    // Prefer detail-* images; still allow other assets with high white ratio later.
    $candidates = [];
    foreach ($assetMeta as $aid => $meta) {
        $path = (string)($meta['path'] ?? '');
        $objectKey = (string)($meta['object_key'] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $isDetail = str_contains($objectKey, '/detail-') || str_contains(basename($objectKey), 'detail-');
        $white = whiteRatio($path);
        $meta['white_ratio'] = $white;
        $meta['is_detail'] = $isDetail;
        $assetMeta[$aid] = $meta;
        if ($isDetail || $white >= $minWhite) {
            $candidates[$aid] = $meta;
        }
    }
    fwrite(STDERR, 'Candidate images: ' . count($candidates) . "\n");

    // Parallel OCR via temp job list
    $jobs = [];
    foreach ($candidates as $aid => $meta) {
        $white = (float)($meta['white_ratio'] ?? 0);
        $mode = $white >= $minWhite ? 'full' : 'top';
        if ($white < 12.0 && $mode === 'top') {
            continue; // pure photo
        }
        $jobs[$aid] = [
            'asset_id' => $aid,
            'path' => (string)$meta['path'],
            'mode' => $mode,
            'white_ratio' => $white,
            'products' => $meta['products'],
            'object_key' => (string)($meta['object_key'] ?? ''),
        ];
    }
    fwrite(STDERR, 'OCR jobs: ' . count($jobs) . " (workers={$workers})\n");

    $ocrResults = runOcrJobs($jobs, $workers, $artifactDir);
    $hits = [];
    $soft = [];
    $skipped = 0;
    foreach ($jobs as $aid => $job) {
        $ocr = trim((string)($ocrResults[$aid] ?? ''));
        $kind = classifyOcr($ocr, (string)$job['mode'], (float)$job['white_ratio']);
        $entry = [
            'asset_id' => $aid,
            'object_key' => $job['object_key'],
            'products' => $job['products'],
            'white_ratio' => $job['white_ratio'],
            'mode' => $job['mode'],
            'kind' => $kind,
            'ocr' => $ocr,
            'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $ocr) ?? '', 0, 240),
        ];
        if ($kind === 'photo' || $kind === 'unknown') {
            $skipped++;
            continue;
        }
        if (in_array($kind, ['measurement_chart', 'gender_weight_chart', 'product_info', 'care_notes', 'generic_text'], true)) {
            $hits[$aid] = $entry;
        } else {
            $soft[$aid] = $entry; // section_heading
        }
    }

    $scan['assets'] = $hits + $soft;
    $scan['hits'] = array_values($hits);
    $scan['soft_hits'] = array_values($soft);
    $scan['stats'] = [
        'products' => count($rows),
        'unique_assets' => count($ids),
        'candidates' => count($candidates),
        'ocr_jobs' => count($jobs),
        'hits' => count($hits),
        'soft_hits' => count($soft),
        'skipped' => $skipped,
    ];
    file_put_contents($scanFile, json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fwrite(STDERR, 'Scan written: ' . $scanFile . "\n");
    fwrite(STDERR, json_encode($scan['stats'], JSON_UNESCAPED_UNICODE) . "\n");
} else {
    $scan = json_decode((string)file_get_contents($scanFile), true, 512, JSON_THROW_ON_ERROR);
    fwrite(STDERR, "Loaded scan {$scanFile}\n");
}

if (!$apply && !isset($options['dry-run']) && !$doScan) {
    // nothing else
}

$entries = array_merge(
    is_array($scan['hits'] ?? null) ? $scan['hits'] : [],
    is_array($scan['soft_hits'] ?? null) ? $scan['soft_hits'] : [],
);
if ($productFilter > 0) {
    $entries = array_values(array_filter(
        $entries,
        static fn(array $e): bool => in_array($productFilter, array_map('intval', $e['products'] ?? []), true),
    ));
}

/** @var array<string, array{kind:string,html_zh:string,html_en:string,products:list<int>}> $replacements */
$replacements = [];
$buildFailed = [];
foreach ($entries as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $assetId = strtolower(trim((string)($entry['asset_id'] ?? '')));
    $kind = (string)($entry['kind'] ?? '');
    $ocr = trim((string)($entry['ocr'] ?? ''));
    $products = array_values(array_unique(array_map('intval', $entry['products'] ?? [])));
    if ($assetId === '' || $ocr === '') {
        continue;
    }
    [$htmlZh, $htmlEn] = buildHtmlPair($kind, $ocr);
    if ($htmlZh === '') {
        $buildFailed[] = ['asset_id' => $assetId, 'kind' => $kind, 'snippet' => mb_substr($ocr, 0, 120)];
        continue;
    }
    $replacements[$assetId] = [
        'kind' => $kind,
        'html_zh' => $htmlZh,
        'html_en' => $htmlEn !== '' ? $htmlEn : translateFragmentToEn($htmlZh),
        'products' => $products,
    ];
}

fwrite(STDERR, 'Replacements ready: ' . count($replacements) . '; build_failed=' . count($buildFailed) . "\n");

$productIds = [];
foreach ($replacements as $row) {
    foreach ($row['products'] as $pid) {
        if ($pid > 0) {
            $productIds[$pid] = true;
        }
    }
}
$productIds = array_keys($productIds);
sort($productIds);
if ($limit > 0 && !$productFilter) {
    $productIds = array_slice($productIds, 0, $limit);
}

$englishLocales = ['en_US', 'ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK'];
$chineseLocales = ['zh_Hans_CN', ''];

/** @var AttributeValueRepository|null $attributes */
$attributes = null;
if ($apply) {
    $attributes = ObjectManager::getInstance(AttributeValueRepository::class);
}

$plans = [];
$skippedProducts = [];
foreach ($productIds as $productId) {
    $descStmt = $pdo->prepare(
        "SELECT locale, value_text FROM w_product_ws_{$websiteId}_attribute_value
         WHERE entity_type = 'product' AND entity_id = :id AND attribute_code = 'description'
         ORDER BY locale, length(value_text) DESC"
    );
    $descStmt->execute(['id' => $productId]);
    /** @var array<string, string> $locales */
    $locales = [];
    while ($row = $descStmt->fetch(PDO::FETCH_ASSOC)) {
        $loc = (string)$row['locale'];
        if (!isset($locales[$loc])) {
            $locales[$loc] = (string)$row['value_text'];
        }
    }
    $sourceHtml = $locales['zh_Hans_CN'] ?? $locales[''] ?? '';
    if ($sourceHtml === '' || !str_contains($sourceHtml, 'data-weline-product-description="1688"')) {
        $skippedProducts[] = ['product_id' => $productId, 'reason' => 'missing_1688_description'];
        continue;
    }

    $updatedZh = $sourceHtml;
    $replaced = [];
    foreach ($replacements as $assetId => $replacement) {
        if (!in_array($productId, $replacement['products'], true)) {
            continue;
        }
        if (!str_contains($updatedZh, 'asset://' . $assetId)) {
            continue;
        }
        $next = DetailDescriptionTextifier::replaceAssetImageWithHtml(
            $updatedZh,
            $assetId,
            $replacement['html_zh'],
        );
        if ($next !== $updatedZh) {
            $updatedZh = $next;
            $replaced[] = $assetId;
        }
    }
    if ($replaced === []) {
        $skippedProducts[] = ['product_id' => $productId, 'reason' => 'no_asset_replaced'];
        continue;
    }

    $updatedEn = $sourceHtml;
    foreach ($replaced as $assetId) {
        $replacement = $replacements[$assetId];
        $updatedEn = DetailDescriptionTextifier::replaceAssetImageWithHtml(
            $updatedEn,
            $assetId,
            $replacement['html_en'],
        );
    }

    $plan = [
        'product_id' => $productId,
        'replaced_assets' => $replaced,
        'kinds' => array_values(array_unique(array_map(
            static fn(string $id): string => (string)($replacements[$id]['kind'] ?? ''),
            $replaced,
        ))),
    ];
    $plans[] = $plan;

    if ($apply && $attributes instanceof AttributeValueRepository) {
        foreach ($chineseLocales as $locale) {
            $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $updatedZh, true);
        }
        foreach ($englishLocales as $locale) {
            $existing = trim((string)($locales[$locale] ?? ''));
            if ($existing !== '' && !str_contains($existing, 'asset://')) {
                // Already localized without assets; skip overwrite unless it still has replaced assets.
                $needs = false;
                foreach ($replaced as $assetId) {
                    if (str_contains($existing, 'asset://' . $assetId)) {
                        $needs = true;
                        break;
                    }
                }
                if (!$needs) {
                    continue;
                }
            }
            if ($existing === '' || str_contains($existing, 'data-weline-product-description="1688"')) {
                $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $updatedEn, true);
            }
        }
    }
}

$purgeResults = [];
if ($apply && $plans !== []) {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'batch_detail_baked_textified',
        ['product_ids' => array_column($plans, 'product_id')],
    );
    try {
        ObjectManager::getInstance(\Weline\Product\Service\ProductStorefrontCacheInvalidator::class)
            ->clearForCatalogChange('batch_detail_baked_textified');
    } catch (Throwable) {
        // best-effort
    }

    $toPurge = [];
    foreach ($plans as $plan) {
        foreach ((array)($plan['replaced_assets'] ?? []) as $assetId) {
            $toPurge[] = strtolower(trim((string)$assetId));
        }
    }
    $toPurge = array_values(array_unique(array_filter($toPurge)));
    $stillUsed = static function (string $assetId) use ($pdo): bool {
        $like = '%asset://' . $assetId . '%';
        $tables = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'
             AND tablename LIKE 'w_product_ws_%_attribute_value'",
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM {$table}
                 WHERE entity_type = 'product' AND attribute_code = 'description'
                   AND value_text LIKE :like LIMIT 1",
            );
            $stmt->execute(['like' => $like]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        $mediaTables = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'
             AND tablename LIKE 'w_product_ws_%_media'",
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($mediaTables as $table) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE asset_id = :id LIMIT 1");
            $stmt->execute(['id' => $assetId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        return false;
    };
    /** @var DetailTextifiedAssetPurger $purger */
    $purger = ObjectManager::getInstance(DetailTextifiedAssetPurger::class);
    $purgeResults = $purger->purgeReplaced($toPurge, $stillUsed, $root . '/pub/media');
}

$report = [
    'mode' => $apply ? 'apply' : ($doScan ? 'scan' : 'dry-run'),
    'scan_file' => $scanFile,
    'replacements' => count($replacements),
    'products_planned' => count($plans),
    'skipped_products' => count($skippedProducts),
    'build_failed' => count($buildFailed),
    'purged_assets' => count($purgeResults),
    'purge_deleted' => count(array_filter(
        $purgeResults,
        static fn(array $row): bool => ($row['status'] ?? '') === 'deleted',
    )),
    'kind_counts' => array_count_values(array_column($replacements, 'kind')),
    'plans_preview' => array_slice($plans, 0, 30),
    'skipped_preview' => array_slice($skippedProducts, 0, 20),
    'build_failed_preview' => array_slice($buildFailed, 0, 20),
    'purge_preview' => array_slice($purgeResults, 0, 30),
];
$out = $artifactDir . '/batch-' . ($apply ? 'apply' : 'plan') . '-' . date('Ymd-His') . '.json';
file_put_contents($out, json_encode($report + [
    'plans' => $plans,
    'skipped_products' => $skippedProducts,
    'purge_results' => $purgeResults,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$report['report_path'] = $out;
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

// --- helpers ---

function whiteRatio(string $path): float
{
    $im = @imagecreatefromjpeg($path);
    if (!$im) {
        $im = @imagecreatefrompng($path);
    }
    if (!$im) {
        return 0.0;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $step = max(1, (int)($w / 70));
    $white = 0;
    $n = 0;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $rgb = imagecolorat($im, $x, $y);
            $r = ($rgb >> 16) & 255;
            $g = ($rgb >> 8) & 255;
            $b = $rgb & 255;
            if ($r > 235 && $g > 235 && $b > 235) {
                $white++;
            }
            $n++;
        }
    }
    imagedestroy($im);

    return $n > 0 ? round(100.0 * $white / $n, 2) : 0.0;
}

/**
 * @param array<string, array<string, mixed>> $jobs
 * @return array<string, string>
 */
function runOcrJobs(array $jobs, int $workers, string $artifactDir): array
{
    $jobDir = $artifactDir . '/ocr-jobs-' . date('Ymd-His');
    @mkdir($jobDir, 0777, true);
    $listFile = $jobDir . '/jobs.tsv';
    $fh = fopen($listFile, 'wb');
    if ($fh === false) {
        return [];
    }
    foreach ($jobs as $aid => $job) {
        $mode = (string)$job['mode'];
        $path = (string)$job['path'];
        $ocrPath = $path;
        if ($mode === 'top') {
            $crop = $jobDir . '/' . $aid . '-top.jpg';
            if (cropTopBand($path, $crop, 0.14)) {
                $ocrPath = $crop;
            }
        }
        fwrite($fh, $aid . "\t" . $ocrPath . "\t" . $jobDir . '/' . $aid . ".txt\n");
    }
    fclose($fh);

    $cmd = sprintf(
        'cat %s | xargs -P %d -n 1 -I {} bash -lc %s',
        escapeshellarg($listFile),
        $workers,
        escapeshellarg(
            'IFS=$\'\t\' read -r aid img out <<< "{}"; '
            . 'tesseract "$img" "${out%.txt}" -l chi_sim+eng --psm 6 >/dev/null 2>&1 || true'
        ),
    );
    // xargs with TSV is awkward; use a simpler parallel loop.
    $lines = file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $chunks = array_chunk($lines, max(1, (int)ceil(count($lines) / $workers)));
    $children = [];
    foreach ($chunks as $i => $chunk) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            // fallback serial for this chunk
            foreach ($chunk as $line) {
                [$aid, $img, $out] = explode("\t", $line);
                ocrOne($img, preg_replace('/\.txt$/', '', $out) ?: $out);
            }
            continue;
        }
        if ($pid === 0) {
            foreach ($chunk as $line) {
                [$aid, $img, $out] = explode("\t", $line);
                ocrOne($img, preg_replace('/\.txt$/', '', $out) ?: $out);
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach ($jobs as $aid => $_job) {
        $txt = $jobDir . '/' . $aid . '.txt';
        $results[$aid] = is_file($txt) ? (string)file_get_contents($txt) : '';
    }

    return $results;
}

function ocrOne(string $img, string $outBase): void
{
    if (!is_file($img)) {
        return;
    }
    $cmd = 'tesseract ' . escapeshellarg($img) . ' ' . escapeshellarg($outBase)
        . ' -l chi_sim+eng --psm 6 >/dev/null 2>&1';
    exec($cmd);
}

function cropTopBand(string $src, string $dst, float $ratio): bool
{
    $im = @imagecreatefromjpeg($src);
    if (!$im) {
        return false;
    }
    $w = imagesx($im);
    $h = max(20, (int)(imagesy($im) * $ratio));
    $crop = imagecreatetruecolor($w, $h);
    imagecopy($crop, $im, 0, 0, 0, 0, $w, $h);
    $ok = imagejpeg($crop, $dst, 92);
    imagedestroy($crop);
    imagedestroy($im);

    return (bool)$ok;
}

function classifyOcr(string $ocr, string $mode, float $white): string
{
    $text = trim($ocr);
    if ($text === '') {
        return 'unknown';
    }
    $han = preg_match_all('/\p{Han}/u', $text) ?: 0;
    if ($mode === 'top') {
        if (preg_match('/面料特性|模特展示|产品细节|细节展示|工艺细节|穿着效果|实拍展示|洗护说明|注意事项|购物须知|尺码说明|温馨提示|品牌介绍|关于我们/u', $text) === 1) {
            return 'section_heading';
        }
        // short Chinese title band
        if ($han >= 2 && $han <= 12 && mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') <= 16) {
            return 'section_heading';
        }

        return 'photo';
    }

    if (preg_match('/建议体重|男女分码|女款.*选.*码|男款.*选.*码/u', $text) === 1) {
        return 'gender_weight_chart';
    }
    if (preg_match('/尺码参考|尺码表|衣长|胸围|裙长|通袖|袖长|腰围|裤长|系带长/u', $text) === 1) {
        return 'measurement_chart';
    }
    if (preg_match('/产品信息|基本信息|舒适度|厚薄指数|弹力指数|柔软指数|版型指数/u', $text) === 1) {
        return 'product_info';
    }
    if (preg_match('/洗护|洗涤|注意事项|购物须知|不可漂白|不可烘干/u', $text) === 1) {
        return 'care_notes';
    }
    if ($white >= 45.0 && $han >= 8) {
        return 'generic_text';
    }
    if (preg_match('/面料特性|模特展示|产品细节|细节展示/u', $text) === 1 && $han <= 20) {
        return 'section_heading';
    }

    return $han >= 6 && $white >= 40.0 ? 'generic_text' : 'photo';
}

/**
 * @return array{0:string,1:string}
 */
function buildHtmlPair(string $kind, string $ocr): array
{
    $ocr = trim($ocr);
    if ($kind === 'gender_weight_chart') {
        $parsed = DetailDescriptionTextifier::parseGenderWeightChartFromOcr($ocr);
        if ($parsed !== null) {
            return [
                DetailDescriptionTextifier::buildGenderWeightSizeChartZh($parsed['women'], $parsed['men']),
                DetailDescriptionTextifier::buildGenderWeightSizeChartEn($parsed['women'], $parsed['men']),
            ];
        }
    }

    if ($kind === 'measurement_chart') {
        $tables = parseMeasurementTables($ocr);
        if ($tables !== []) {
            return [
                DetailDescriptionTextifier::buildMeasurementSizeChartZh($tables),
                DetailDescriptionTextifier::buildMeasurementSizeChartEn(translateMeasurementTables($tables)),
            ];
        }
    }

    if ($kind === 'product_info') {
        [$basicsZh, $comfortZh] = parseProductInfo($ocr);
        if ($basicsZh !== [] || $comfortZh !== []) {
            $zh = DetailDescriptionTextifier::buildProductInfoPanelZh(
                $basicsZh !== [] ? $basicsZh : ['说明' => mb_substr(preg_replace('/\s+/u', ' ', $ocr) ?? '', 0, 120)],
                $comfortZh,
            );
            $en = DetailDescriptionTextifier::buildProductInfoPanelEn(
                translateBasics($basicsZh),
                translateComfort($comfortZh),
            );

            return [$zh, $en];
        }
    }

    if ($kind === 'section_heading') {
        $heading = extractHeading($ocr);
        if ($heading !== '') {
            $zh = DetailDescriptionTextifier::buildSectionHeading($heading, 'section');
            $en = DetailDescriptionTextifier::buildSectionHeading(translateHeading($heading), 'section');

            return [$zh, $en];
        }
    }

    if (in_array($kind, ['care_notes', 'generic_text', 'measurement_chart', 'product_info'], true)) {
        $zh = buildGenericListHtml($ocr, $kind);
        if ($zh !== '') {
            return [$zh, translateFragmentToEn($zh)];
        }
    }

    return ['', ''];
}

/**
 * @return list<array{title:string,headers:list<string>,rows:list<list<string>}>}>
 */
function parseMeasurementTables(string $ocr): array
{
    // Best-effort: detect part titles and numeric grids.
    $lines = preg_split('/\R+/u', $ocr) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    $tables = [];
    $currentTitle = '尺码';
    $buffer = [];
    $flush = static function () use (&$tables, &$currentTitle, &$buffer): void {
        if ($buffer === []) {
            return;
        }
        $sizes = [];
        $metrics = [];
        foreach ($buffer as $line) {
            if (preg_match('/^(S|M|L|XL|XXL|2XL|3XL|XS)\b/iu', $line, $m) === 1
                && preg_match_all('/\d{2,3}/', $line, $nums) > 0) {
                $sizes[strtoupper($m[1])] = $nums[0];
            }
        }
        if (count($sizes) >= 2) {
            $headers = array_merge(['项目'], array_keys($sizes));
            $colCount = max(array_map('count', $sizes));
            $labels = ['尺寸1', '尺寸2', '尺寸3', '尺寸4', '尺寸5'];
            // Prefer Chinese metric labels if present nearby in OCR elsewhere — keep generic.
            $rows = [];
            for ($i = 0; $i < $colCount; $i++) {
                $row = [$labels[$i] ?? ('项' . ($i + 1))];
                foreach ($sizes as $vals) {
                    $row[] = (string)($vals[$i] ?? '');
                }
                $rows[] = $row;
            }
            $tables[] = ['title' => $currentTitle, 'headers' => $headers, 'rows' => $rows];
        }
        $buffer = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^(大袖衫|诃子裙|上衣|下裙|外套|马面裙|襦裙|裤子|衬衫|披帛|云肩|腰封|背心|吊带|半臂|比甲|褙子|道袍|圆领袍).{0,8}$/u', $line) === 1) {
            $flush();
            $currentTitle = $line;
            continue;
        }
        $buffer[] = $line;
    }
    $flush();

    return $tables;
}

/**
 * @param list<array{title:string,headers:list<string>,rows:list<list<string>}>}> $tables
 * @return list<array{title:string,headers:list<string>,rows:list<list<string>}>}>
 */
function translateMeasurementTables(array $tables): array
{
    $map = [
        '大袖衫' => 'Daxiushan (large-sleeve robe)',
        '诃子裙' => 'Hezi skirt',
        '上衣' => 'Top',
        '下裙' => 'Skirt',
        '外套' => 'Outerwear',
        '马面裙' => 'Mamian skirt',
        '襦裙' => 'Ruqun',
        '尺码' => 'Size',
        '项目' => 'Item',
        '衣长' => 'Length',
        '胸围' => 'Bust',
        '通袖长' => 'Sleeve span',
        '裙长' => 'Skirt length',
        '系带长' => 'Tie length',
        '腰围' => 'Waist',
        '袖长' => 'Sleeve length',
        '裤长' => 'Pants length',
    ];
    $out = [];
    foreach ($tables as $table) {
        $title = (string)$table['title'];
        $headers = [];
        foreach ($table['headers'] as $h) {
            $headers[] = $map[$h] ?? $h;
        }
        $rows = [];
        foreach ($table['rows'] as $row) {
            $nr = [];
            foreach ($row as $i => $cell) {
                $nr[] = $i === 0 ? ($map[$cell] ?? $cell) : $cell;
            }
            $rows[] = $nr;
        }
        $out[] = [
            'title' => $map[$title] ?? $title,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    return $out;
}

/**
 * @return array{0:array<string,string>,1:list<array{label:string,options:list<string>,selected:string}>}
 */
function parseProductInfo(string $ocr): array
{
    $basics = [];
    if (preg_match('/名称[:：]\s*([^\n]+)/u', $ocr, $m)) {
        $basics['名称'] = trim($m[1]);
    }
    if (preg_match('/尺码[:：]\s*([^\n]+)/u', $ocr, $m)) {
        $basics['尺码'] = trim($m[1]);
    }
    if (preg_match('/颜色[:：]\s*([^\n]+)/u', $ocr, $m)) {
        $basics['颜色'] = trim($m[1]);
    }
    if (preg_match('/面料[:：]\s*([^\n]+)/u', $ocr, $m)) {
        $basics['面料'] = trim($m[1]);
    }
    // OCR often garbles; fall back to keywords.
    if ($basics === [] && preg_match('/图片色/u', $ocr) === 1) {
        $basics['颜色'] = '图片色';
    }
    if (!isset($basics['尺码']) && preg_match('/S\s*[-–~]\s*L/u', $ocr, $m) === 1) {
        $basics['尺码'] = 'S–L';
    }

    $comfort = [];
    $defs = [
        ['厚薄指数', ['超薄', '微薄', '适中', '厚'], '微薄'],
        ['弹力指数', ['无弹', '微弹', '适中', '弹力'], '无弹'],
        ['柔软指数', ['柔软', '偏软', '适中', '偏硬'], '柔软'],
        ['版型指数', ['紧身', '修身', '合体', '宽松'], '宽松'],
    ];
    foreach ($defs as [$label, $options, $defaultSelected]) {
        $selected = $defaultSelected;
        foreach ($options as $opt) {
            if (mb_strpos($ocr, $opt) !== false) {
                // Prefer explicit option presence; last wins for repeated OCR noise.
                $selected = $opt;
            }
        }
        if (preg_match('/厚薄|弹力|柔软|版型|舒适度|修身|宽松|无弹|微薄/u', $ocr) === 1) {
            $comfort[] = ['label' => $label, 'options' => $options, 'selected' => $selected];
        }
    }
    // If comfort keywords exist but all four not detected cleanly, still keep the four defaults once.
    if ($comfort === [] && preg_match('/舒适度|厚薄|弹力/u', $ocr) === 1) {
        foreach ($defs as [$label, $options, $defaultSelected]) {
            $comfort[] = ['label' => $label, 'options' => $options, 'selected' => $defaultSelected];
        }
    }

    return [$basics, $comfort];
}

/**
 * @param array<string,string> $basics
 * @return array<string,string>
 */
function translateBasics(array $basics): array
{
    $keyMap = ['名称' => 'Name', '尺码' => 'Size', '颜色' => 'Color', '面料' => 'Fabric'];
    $valMap = [
        '图片色' => 'As pictured',
        '亲肤柔软' => 'Soft and skin-friendly',
        '四季春' => 'Sijichun (Four Seasons Spring)',
    ];
    $out = [];
    foreach ($basics as $k => $v) {
        $out[$keyMap[$k] ?? $k] = $valMap[$v] ?? $v;
    }

    return $out;
}

/**
 * @param list<array{label:string,options:list<string>,selected:string}> $comfort
 * @return list<array{label:string,options:list<string>,selected:string}>
 */
function translateComfort(array $comfort): array
{
    $map = [
        '厚薄指数' => 'Thickness', '弹力指数' => 'Stretch', '柔软指数' => 'Softness', '版型指数' => 'Fit',
        '超薄' => 'Ultra-thin', '微薄' => 'Lightweight', '适中' => 'Moderate', '厚' => 'Thick',
        '无弹' => 'None', '微弹' => 'Slight', '弹力' => 'Stretchy',
        '柔软' => 'Soft', '偏软' => 'Slightly soft', '偏硬' => 'Firm',
        '紧身' => 'Tight', '修身' => 'Slim', '合体' => 'Regular', '宽松' => 'Relaxed',
    ];
    $out = [];
    foreach ($comfort as $row) {
        $out[] = [
            'label' => $map[$row['label']] ?? $row['label'],
            'options' => array_map(static fn(string $o): string => $map[$o] ?? $o, $row['options']),
            'selected' => $map[$row['selected']] ?? $row['selected'],
        ];
    }

    return $out;
}

function extractHeading(string $ocr): string
{
    $text = trim(preg_replace('/\s+/u', '', $ocr) ?? '');
    if (preg_match('/(面料特性|模特展示|产品细节|细节展示|工艺细节|穿着效果|实拍展示|洗护说明|注意事项|购物须知|尺码说明|温馨提示|品牌介绍)/u', $text, $m) === 1) {
        return $m[1];
    }
    $text = trim(preg_replace('/[^\p{Han}A-Za-z0-9]/u', '', $ocr) ?? '');
    if (mb_strlen($text) >= 2 && mb_strlen($text) <= 12) {
        return $text;
    }

    return '';
}

function translateHeading(string $heading): string
{
    $map = [
        '面料特性' => 'Fabric characteristics',
        '模特展示' => 'Model showcase',
        '产品细节' => 'Product details',
        '细节展示' => 'Detail showcase',
        '工艺细节' => 'Craftsmanship details',
        '穿着效果' => 'On-body look',
        '实拍展示' => 'Real photos',
        '洗护说明' => 'Care instructions',
        '注意事项' => 'Notes',
        '购物须知' => 'Shopping notes',
        '尺码说明' => 'Size notes',
        '温馨提示' => 'Tips',
        '品牌介绍' => 'About the brand',
        '产品信息' => 'Product information',
        '尺码参考表' => 'Size reference chart',
        '尺码建议表' => 'Size suggestion chart',
    ];

    return $map[$heading] ?? $heading;
}

function buildGenericListHtml(string $ocr, string $kind): string
{
    $lines = preg_split('/\R+/u', $ocr) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static function (string $line): bool {
        if ($line === '' || mb_strlen($line) < 2) {
            return false;
        }
        // drop pure noise
        return preg_match('/[\p{Han}A-Za-z0-9]/u', $line) === 1;
    }));
    if ($lines === []) {
        return '';
    }
    $title = match ($kind) {
        'care_notes' => '洗护与注意事项',
        'measurement_chart' => '尺码参考',
        'product_info' => '产品信息',
        default => '详情说明',
    };
    foreach ($lines as $line) {
        if (preg_match('/尺码|洗护|产品信息|注意事项|参考/u', $line) === 1) {
            $title = mb_substr($line, 0, 24);
            break;
        }
    }
    $items = '';
    foreach (array_slice($lines, 0, 18) as $line) {
        if ($line === $title) {
            continue;
        }
        $items .= '<li>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }
    if ($items === '') {
        return '';
    }

    return DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-text weline-detail-text--section" data-weline-detail-text="generic">'
        . '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
        . '<ul>' . $items . '</ul></div>'
    );
}

function translateFragmentToEn(string $htmlZh): string
{
    $map = [
        '洗护与注意事项' => 'Care & notes',
        '尺码参考' => 'Size reference',
        '产品信息' => 'Product information',
        '详情说明' => 'Product details',
        '尺码参考表' => 'Size reference chart',
        '尺码建议表' => 'Size suggestion chart',
        '以上数值来自商品详情图文字识别，手工测量可能存在 1-3 cm 误差。' => 'Values recognized from the listing graphic; hand measurements may vary by 1–3 cm.',
        '单位：厘米（cm）。手工测量可能存在 1–3 cm 误差。' => 'Unit: centimeters (cm). Hand measurements may vary by 1–3 cm.',
        '面料特性' => 'Fabric characteristics',
        '模特展示' => 'Model showcase',
        '基本信息' => 'Basics',
        '舒适度信息' => 'Comfort & fit',
        '名称' => 'Name',
        '尺码' => 'Size',
        '颜色' => 'Color',
        '面料' => 'Fabric',
        '图片色' => 'As pictured',
        '亲肤柔软' => 'Soft and skin-friendly',
        '厚薄指数' => 'Thickness',
        '弹力指数' => 'Stretch',
        '柔软指数' => 'Softness',
        '版型指数' => 'Fit',
        '超薄' => 'Ultra-thin',
        '微薄' => 'Lightweight',
        '适中' => 'Moderate',
        '厚' => 'Thick',
        '无弹' => 'None',
        '微弹' => 'Slight',
        '弹力' => 'Stretchy',
        '柔软' => 'Soft',
        '偏软' => 'Slightly soft',
        '偏硬' => 'Firm',
        '紧身' => 'Tight',
        '修身' => 'Slim',
        '合体' => 'Regular',
        '宽松' => 'Relaxed',
        '大袖衫' => 'Daxiushan',
        '诃子裙' => 'Hezi skirt',
        '衣长' => 'Length',
        '胸围' => 'Bust',
        '通袖长' => 'Sleeve span',
        '裙长' => 'Skirt length',
        '系带长' => 'Tie length',
        '项目' => 'Item',
        '说明' => 'Notes',
    ];

    return DetailDescriptionTextifier::mapTextNodes(
        $htmlZh,
        static function (string $text) use ($map): string {
            $t = trim($text);
            if ($t === '') {
                return $text;
            }
            if (isset($map[$t])) {
                return $map[$t];
            }
            // replace known tokens inside longer OCR lines
            $out = $t;
            foreach ($map as $zh => $en) {
                if (mb_strpos($out, $zh) !== false) {
                    $out = str_replace($zh, $en, $out);
                }
            }

            return $out;
        },
    );
}
