<?php

declare(strict_types=1);

/**
 * Convert text-heavy product detail images (size charts / care notes) into HTML
 * and backfill en_US description rows for multilingual storefronts.
 *
 * php app/code/Weline/Product/scripts/remediate-detail-text-charts.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-detail-text-charts.php --apply --zh-only
 * php app/code/Weline/Product/scripts/remediate-detail-text-charts.php --apply --product=84 --force-en
 * Optional: --website=0 --limit=20 --scan=var/hanfu-1688/detail-textify/text-heavy-scan.json
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:', 'product:', 'limit:', 'force-en', 'zh-only', 'scan:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$forceEn = isset($options['force-en']);
$zhOnly = isset($options['zh-only']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productFilter = isset($options['product']) ? max(0, (int)$options['product']) : 0;
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
$root = dirname(__DIR__, 5);
$artifactDir = $root . '/var/hanfu-1688/detail-textify';
$scanPath = (string)($options['scan'] ?? ($artifactDir . '/text-heavy-scan.json'));
if (!is_file($scanPath)) {
    fwrite(STDERR, "Scan report missing: {$scanPath}\n");
    exit(2);
}

/** @var array<string, mixed> $scan */
$scan = json_decode((string)file_get_contents($scanPath), true, 512, JSON_THROW_ON_ERROR);
$hits = array_values(array_filter(
    array_merge(
        is_array($scan['hits'] ?? null) ? $scan['hits'] : [],
        is_array($scan['soft_hits'] ?? null) ? $scan['soft_hits'] : [],
    ),
    static function (array $hit) use ($productFilter): bool {
        if ($productFilter <= 0) {
            return true;
        }
        return in_array($productFilter, array_map('intval', $hit['products'] ?? []), true);
    },
));
if ($limit > 0) {
    $hits = array_slice($hits, 0, $limit);
}

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

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$verifiedOcr = [
    'edef8b30-0862-4fd6-9ea1-8afc84b65f9b' =>
        "尺码建议表\n此款为男女分码款亲们可以放心按以下尺码选择\n"
        . "女款 (建议体重70-90斤) 选 S 码\n女款 (建议体重90-110斤) 选 M 码\n"
        . "女款 (建议体重110-120斤) 选 L 码\n女款 (建议体重115-125斤) 选 XL 码\n"
        . "男款 (建议体重90-110斤) 选 M 码\n男款 (建议体重110-120斤) 选 L 码\n"
        . "男款 (建议体重120-140斤) 选 XL 码\n男款 (建议体重140-155斤) 选 2XL 码\n建议按体重拍",
];

$replacements = [];
$skipped = [];
foreach ($hits as $hit) {
    if (!is_array($hit)) {
        continue;
    }
    $assetId = strtolower(trim((string)($hit['asset_id'] ?? '')));
    if ($assetId === '' || !preg_match('/^[a-f0-9-]{36}$/', $assetId)) {
        $skipped[] = ['asset_id' => $assetId, 'reason' => 'invalid_asset'];
        continue;
    }
    $ocr = trim((string)($verifiedOcr[$assetId] ?? ''));
    if ($ocr === '') {
        $ocr = trim(str_replace(' / ', "\n", (string)($hit['snippet'] ?? '')));
    }
    $parsed = DetailDescriptionTextifier::parseGenderWeightChartFromOcr($ocr);
    if ($parsed === null) {
        $htmlZh = buildGenericChartHtmlFromOcr($ocr);
        $htmlEn = null;
        $kind = 'generic_text_chart';
        if ($htmlZh === '') {
            $skipped[] = [
                'asset_id' => $assetId,
                'products' => $hit['products'] ?? [],
                'reason' => 'unparsed_chart',
                'snippet' => mb_substr($ocr, 0, 180),
            ];
            continue;
        }
    } else {
        $htmlZh = DetailDescriptionTextifier::buildGenderWeightSizeChartZh($parsed['women'], $parsed['men']);
        $htmlEn = DetailDescriptionTextifier::buildGenderWeightSizeChartEn($parsed['women'], $parsed['men']);
        $kind = 'gender_weight_size_chart';
    }
    $replacements[$assetId] = [
        'asset_id' => $assetId,
        'kind' => $kind,
        'products' => array_values(array_unique(array_map('intval', $hit['products'] ?? []))),
        'html_zh' => $htmlZh,
        'html_en' => $htmlEn,
    ];
}

$productIds = [];
foreach ($replacements as $row) {
    foreach ($row['products'] as $productId) {
        if ($productId > 0) {
            $productIds[$productId] = true;
        }
    }
}
$productIds = array_keys($productIds);
sort($productIds);

$plans = [];
foreach ($productIds as $productId) {
    $descStmt = $pdo->prepare(
        "SELECT locale, value_text FROM w_product_ws_{$websiteId}_attribute_value
         WHERE entity_type = 'product' AND entity_id = :id AND attribute_code = 'description'
         ORDER BY locale"
    );
    $descStmt->execute(['id' => $productId]);
    $locales = [];
    while ($row = $descStmt->fetch(PDO::FETCH_ASSOC)) {
        $locales[(string)$row['locale']] = (string)$row['value_text'];
    }
    $sourceHtml = $locales['zh_Hans_CN'] ?? $locales[''] ?? '';
    if ($sourceHtml === '' || !str_contains($sourceHtml, 'data-weline-product-description="1688"')) {
        $skipped[] = ['product_id' => $productId, 'reason' => 'missing_1688_description'];
        continue;
    }

    $updatedZh = $sourceHtml;
    $replacedAssets = [];
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
            (string)$replacement['html_zh'],
        );
        if ($next !== $updatedZh) {
            $updatedZh = $next;
            $replacedAssets[] = $assetId;
        }
    }
    if ($replacedAssets === []) {
        $skipped[] = ['product_id' => $productId, 'reason' => 'no_asset_replaced'];
        continue;
    }

    $existingEn = trim((string)($locales['en_US'] ?? ''));
    $needsEn = !$zhOnly && ($existingEn === '' || $forceEn || !str_contains($existingEn, 'data-weline-product-description="1688"'));
    $updatedEn = '';
    if ($needsEn && $apply) {
        $updatedEn = $updatedZh;
        foreach ($replacements as $assetId => $replacement) {
            if (!in_array($assetId, $replacedAssets, true)) {
                continue;
            }
            $zhFragment = (string)$replacement['html_zh'];
            $enFragment = (string)($replacement['html_en'] ?? '');
            if ($enFragment === '') {
                // Generic OCR fragments stay Chinese in bulk runs; use --force-en with a
                // small --product/--limit set when high-fidelity EN copy is required.
                continue;
            }
            if ($zhFragment !== '' && str_contains($updatedEn, $zhFragment)) {
                $updatedEn = str_replace($zhFragment, $enFragment, $updatedEn);
            }
        }
        if ($updatedEn === $updatedZh && !$forceEn) {
            // No structured EN fragment available; skip writing a Chinese-looking en_US row.
            $updatedEn = '';
        }
    } elseif ($needsEn) {
        $updatedEn = '[pending_en_translation]';
    }

    $plan = [
        'product_id' => $productId,
        'replaced_assets' => $replacedAssets,
        'zh_changed' => $updatedZh !== $sourceHtml,
        'en_write' => $updatedEn !== '' && $updatedEn !== '[pending_en_translation]',
        'zh_html' => $updatedZh,
        'en_html' => $updatedEn === '[pending_en_translation]' ? '' : $updatedEn,
    ];
    $plans[] = $plan;

    if ($apply) {
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', 'zh_Hans_CN', $plan['zh_html'], true);
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', '', $plan['zh_html'], true);
        if ($plan['en_write'] && $plan['en_html'] !== '') {
            $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', 'en_US', $plan['en_html'], true);
        }
    }
}

if ($apply && $plans !== []) {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'detail_text_charts_textified',
        ['product_ids' => array_column($plans, 'product_id')],
    );
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'scan' => $scanPath,
    'hit_candidates' => count($hits),
    'replacements_ready' => count($replacements),
    'products_planned' => count($plans),
    'skipped' => $skipped,
    'plans' => array_map(static function (array $plan): array {
        return [
            'product_id' => $plan['product_id'],
            'replaced_assets' => $plan['replaced_assets'],
            'zh_changed' => $plan['zh_changed'],
            'en_write' => $plan['en_write'],
            'zh_preview' => mb_substr(strip_tags((string)$plan['zh_html']), 0, 180),
            'en_preview' => mb_substr(strip_tags((string)$plan['en_html']), 0, 180),
        ];
    }, $plans),
];
@mkdir($artifactDir, 0777, true);
$outPath = $artifactDir . '/remediate-' . ($apply ? 'apply' : 'dry-run') . '-' . date('Ymd-His') . '.json';
file_put_contents($outPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$report['report_path'] = $outPath;
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

/**
 * Fallback for measurement / care charts that are not gender-weight templates.
 */
function buildGenericChartHtmlFromOcr(string $ocr): string
{
    $ocr = trim($ocr);
    if ($ocr === '') {
        return '';
    }
    $lines = preg_split('/\R+/u', $ocr) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    if ($lines === []) {
        return '';
    }

    $title = '详情说明';
    foreach ($lines as $line) {
        if (preg_match('/尺码|洗护|洗涤|测量|注意事项|产品信息/u', $line) === 1) {
            $title = mb_substr($line, 0, 40);
            break;
        }
    }

    // Prefer table-looking numeric rows: size + several numbers
    $rows = [];
    foreach ($lines as $line) {
        if (preg_match('/\b([SMLX0-9]{1,4}|XS|XXL|2XL|3XL|\d{2,3})\b/u', $line) !== 1) {
            continue;
        }
        if (preg_match_all('/\d+(?:\.\d+)?/u', $line, $nums) > 0 && count($nums[0]) >= 2) {
            $rows[] = $line;
        }
    }

    if ($rows !== []) {
        $body = '';
        foreach (array_slice($rows, 0, 16) as $row) {
            $body .= '<li>' . htmlspecialchars($row, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        return DetailDescriptionTextifier::sanitizeFragment(
            '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3><ul>' . $body . '</ul>'
            . '<p>以上数值来自商品详情图文字识别，手工测量可能存在 1-3 cm 误差。</p>'
        );
    }

    // Care / notice paragraphs
    if (preg_match('/洗护|洗涤|注意事项|购物须知/u', $ocr) === 1) {
        $items = '';
        foreach (array_slice($lines, 0, 12) as $line) {
            if (mb_strlen($line) < 4) {
                continue;
            }
            $items .= '<li>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        if ($items === '') {
            return '';
        }
        return DetailDescriptionTextifier::sanitizeFragment(
            '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3><ul>' . $items . '</ul>'
        );
    }

    return '';
}
