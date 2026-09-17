<?php

declare(strict_types=1);

/**
 * Replace baked Chinese detail graphics with semantic HTML for one product.
 *
 * Vision-verified for 花朝记 诃子裙 product 175 (offer 824598076786):
 * - product-info panel
 * - measurement size chart
 * - section headings (fabric / model showcase)
 *
 * php app/code/Weline/Product/scripts/remediate-product-detail-baked-zh-text.php --dry-run --product=175
 * php app/code/Weline/Product/scripts/remediate-product-detail-baked-zh-text.php --apply --product=175
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\DetailTextifiedAssetPurger;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:', 'product:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = max(0, (int)($options['product'] ?? 175));
if ($productId < 1) {
    fwrite(STDERR, "Invalid --product\n");
    exit(2);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$assetProductInfo = 'c4639f19-e73f-45b5-bcc0-7a101998a560';
$assetSizeChart = '95bf871a-2bbc-45c8-ba07-3c5bdc9ac796';
$assetFabric = 'b506b46c-c6ac-486c-9212-5f75745fa70a';
$assetModel = '67a824d5-afa2-4f2e-9f21-2204eefd1d0c';

$infoZh = DetailDescriptionTextifier::buildProductInfoPanelZh(
    [
        '名称' => '四季春',
        '尺码' => 'S–L',
        '颜色' => '图片色',
        '面料' => '亲肤柔软',
    ],
    [
        [
            'label' => '厚薄指数',
            'options' => ['超薄', '微薄', '适中', '厚'],
            'selected' => '微薄',
        ],
        [
            'label' => '弹力指数',
            'options' => ['无弹', '微弹', '适中', '弹力'],
            'selected' => '无弹',
        ],
        [
            'label' => '柔软指数',
            'options' => ['柔软', '偏软', '适中', '偏硬'],
            'selected' => '柔软',
        ],
        [
            'label' => '版型指数',
            'options' => ['紧身', '修身', '合体', '宽松'],
            'selected' => '宽松',
        ],
    ],
);

$infoEn = DetailDescriptionTextifier::buildProductInfoPanelEn(
    [
        'Name' => 'Sijichun (Four Seasons Spring)',
        'Size' => 'S–L',
        'Color' => 'As pictured',
        'Fabric' => 'Soft and skin-friendly',
    ],
    [
        [
            'label' => 'Thickness',
            'options' => ['Ultra-thin', 'Lightweight', 'Moderate', 'Thick'],
            'selected' => 'Lightweight',
        ],
        [
            'label' => 'Stretch',
            'options' => ['None', 'Slight', 'Moderate', 'Stretchy'],
            'selected' => 'None',
        ],
        [
            'label' => 'Softness',
            'options' => ['Soft', 'Slightly soft', 'Moderate', 'Firm'],
            'selected' => 'Soft',
        ],
        [
            'label' => 'Fit',
            'options' => ['Tight', 'Slim', 'Regular', 'Relaxed'],
            'selected' => 'Relaxed',
        ],
    ],
);

$sizeZh = DetailDescriptionTextifier::buildMeasurementSizeChartZh([
    [
        'title' => '大袖衫',
        'headers' => ['尺码', 'S', 'M', 'L'],
        'rows' => [
            ['衣长', '112', '115', '118'],
            ['胸围', '106', '110', '118'],
            ['通袖长', '186', '190', '194'],
        ],
    ],
    [
        'title' => '诃子裙',
        'headers' => ['尺码', 'S', 'M', 'L'],
        'rows' => [
            ['裙长', '116', '122', '126'],
            ['系带长', '150', '150', '150'],
        ],
    ],
]);

$sizeEn = DetailDescriptionTextifier::buildMeasurementSizeChartEn([
    [
        'title' => 'Daxiushan (large-sleeve robe)',
        'headers' => ['Size', 'S', 'M', 'L'],
        'rows' => [
            ['Length', '112', '115', '118'],
            ['Bust', '106', '110', '118'],
            ['Sleeve span', '186', '190', '194'],
        ],
    ],
    [
        'title' => 'Hezi skirt',
        'headers' => ['Size', 'S', 'M', 'L'],
        'rows' => [
            ['Skirt length', '116', '122', '126'],
            ['Tie length', '150', '150', '150'],
        ],
    ],
]);

$fabricZh = DetailDescriptionTextifier::buildSectionHeading('面料特性', 'fabric');
$fabricEn = DetailDescriptionTextifier::buildSectionHeading('Fabric characteristics', 'fabric');
$modelZh = DetailDescriptionTextifier::buildSectionHeading('模特展示', 'model');
$modelEn = DetailDescriptionTextifier::buildSectionHeading('Model showcase', 'model');

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
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

$stmt = $pdo->prepare(
    "SELECT locale, value_text FROM w_product_ws_{$websiteId}_attribute_value
     WHERE entity_type = 'product' AND entity_id = :id AND attribute_code = 'description'
     ORDER BY locale, value_id"
);
$stmt->execute(['id' => $productId]);
/** @var array<string, list<string>> $localeRows */
$localeRows = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $locale = (string)($row['locale'] ?? '');
    $localeRows[$locale][] = (string)$row['value_text'];
}

$pickLongest = static function (array $rows): string {
    $best = '';
    foreach ($rows as $html) {
        if (strlen($html) > strlen($best)) {
            $best = $html;
        }
    }

    return $best;
};

$sourceZh = $pickLongest($localeRows['zh_Hans_CN'] ?? []) ?: $pickLongest($localeRows[''] ?? []);
if ($sourceZh === '' || !str_contains($sourceZh, 'data-weline-product-description="1688"')) {
    fwrite(STDERR, "Missing 1688 description for product {$productId}\n");
    exit(2);
}

$applyLocaleFragments = static function (string $html, bool $english) use (
    $assetProductInfo,
    $assetSizeChart,
    $assetFabric,
    $assetModel,
    $infoZh,
    $infoEn,
    $sizeZh,
    $sizeEn,
    $fabricZh,
    $fabricEn,
    $modelZh,
    $modelEn,
): string {
    $next = $html;
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml(
        $next,
        $assetProductInfo,
        $english ? $infoEn : $infoZh,
    );
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml(
        $next,
        $assetSizeChart,
        $english ? $sizeEn : $sizeZh,
    );
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml(
        $next,
        $assetFabric,
        $english ? $fabricEn : $fabricZh,
    );
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml(
        $next,
        $assetModel,
        $english ? $modelEn : $modelZh,
    );

    return $next;
};

$updatedZh = $applyLocaleFragments($sourceZh, false);
$updatedEn = $applyLocaleFragments($sourceZh, true);

$englishLocales = [
    'en_US', 'ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK',
];
$chineseLocales = ['zh_Hans_CN', ''];

$writes = [];
foreach ($chineseLocales as $locale) {
    $writes[] = ['locale' => $locale, 'html' => $updatedZh, 'kind' => 'zh'];
}
foreach ($englishLocales as $locale) {
    $existing = $pickLongest($localeRows[$locale] ?? []);
    // Only rewrite locales that still carry the baked Chinese image description.
    if ($existing === '' || !str_contains($existing, 'asset://' . $assetSizeChart)) {
        continue;
    }
    $writes[] = ['locale' => $locale, 'html' => $updatedEn, 'kind' => 'en'];
}

$replacedAssetIds = [
    $assetProductInfo,
    $assetSizeChart,
    $assetFabric,
    $assetModel,
];
$purgeResults = [];

if ($apply) {
    foreach ($writes as $write) {
        $attributes->writeExplicit(
            $websiteId,
            0,
            'product',
            $productId,
            'description',
            (string)$write['locale'],
            (string)$write['html'],
            true,
        );
    }
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'detail_baked_zh_textified',
        ['product_ids' => [$productId]],
    );

    $root = dirname(__DIR__, 5);
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
    $purgeResults = $purger->purgeReplaced($replacedAssetIds, $stillUsed, $root . '/pub/media');
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'product_id' => $productId,
    'replaced_assets' => [
        $assetProductInfo => 'product-info',
        $assetSizeChart => 'measurement-chart',
        $assetFabric => 'fabric-heading',
        $assetModel => 'model-heading',
    ],
    'kept_photo_assets' => [
        'fcb6f816-899d-4228-91d3-ce846453334d',
        '3a7ee03b-58ac-4813-b98d-c517d3658403',
        '99f78ddc-f8b4-4989-b4b3-6c3ac9b90f64',
        '194d4d15-7bcd-49cc-ad7c-3d95e1292ead',
    ],
    'purged_assets' => $purgeResults,
    'writes' => array_map(static function (array $write): array {
        return [
            'locale' => $write['locale'],
            'kind' => $write['kind'],
            'preview' => mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$write['html'])) ?? '', 0, 160),
            'has_chart' => str_contains((string)$write['html'], 'measurement-chart'),
            'has_info' => str_contains((string)$write['html'], 'product-info'),
            'remaining_size_asset' => str_contains((string)$write['html'], 'asset://95bf871a-2bbc-45c8-ba07-3c5bdc9ac796'),
        ];
    }, $writes),
];

$artifactDir = dirname(__DIR__, 5) . '/var/hanfu-1688/detail-textify';
@mkdir($artifactDir, 0777, true);
$path = $artifactDir . '/product-' . $productId . '-' . ($apply ? 'apply' : 'dry-run') . '-' . date('Ymd-His') . '.json';
file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$report['report_path'] = $path;
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
