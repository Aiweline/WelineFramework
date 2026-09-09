<?php

declare(strict_types=1);

/**
 * Scan / backfill product-level (non-axis) 1688 listing specifications.
 *
 * Defect: published configurable products whose public EAV only has variant axes
 * (size/style_type/…) while crawl snapshots still carry listing specs (material,
 * 领标, 吊牌, …). Common root cause: import failed with
 * product_attribute_option_invalid after「货源类型」was misfiled into style_type.
 *
 * Usage (single):
 *   php app/code/Weline/Product/scripts/remediate-product-listing-spec-attrs.php \
 *     --product-id=83 --offer-id=687945556432 \
 *     --snapshot=var/hanfu-1688/.../crawl-snapshot.json --dry-run|--apply
 *
 * Usage (bulk scan / auto-fix — MCP「规格修复」):
 *   php .../remediate-product-listing-spec-attrs.php --scan --dry-run
 *   php .../remediate-product-listing-spec-attrs.php --scan --apply
 *   php .../remediate-product-listing-spec-attrs.php --scan --apply --website=0
 */

define('WELINE_HANFU_IMPORT_HELPERS_ONLY', true);
require dirname(__DIR__, 5) . '/app/bootstrap.php';
require __DIR__ . '/import-1688-hanfu-catalog.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Sample\Hanfu1688\OfferEavMapper;
use Weline\Product\Service\ProductAttributeMetadataCatalog;
use Weline\Product\Service\ProductCatalogEavBootstrap;

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';
$scan = in_array('--scan', $argv, true) || in_array('--all-deficient', $argv, true);

$productId = 0;
$offerId = '';
$snapshotPath = '';
$websiteId = 0;
$snapshotRoot = dirname(__DIR__, 5) . '/var/hanfu-1688';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--product-id=')) {
        $productId = max(0, (int)substr($argument, strlen('--product-id=')));
    } elseif (str_starts_with($argument, '--offer-id=')) {
        $offerId = trim(substr($argument, strlen('--offer-id=')));
    } elseif (str_starts_with($argument, '--snapshot=')) {
        $snapshotPath = trim(substr($argument, strlen('--snapshot=')));
    } elseif (str_starts_with($argument, '--website=')) {
        $websiteId = max(0, (int)substr($argument, strlen('--website=')));
    } elseif (str_starts_with($argument, '--snapshot-root=')) {
        $snapshotRoot = trim(substr($argument, strlen('--snapshot-root=')));
    }
}

$manager = ObjectManager::getInstance();
/** @var ProductCatalogEavBootstrap $eavBootstrap */
$eavBootstrap = $manager->get(ProductCatalogEavBootstrap::class);
/** @var ProductAttributeMetadataCatalog $attributeMetadata */
$attributeMetadata = $manager->get(ProductAttributeMetadataCatalog::class);
/** @var AttributeValueRepository $attributes */
$attributes = $manager->get(AttributeValueRepository::class);
$eavBootstrap->ensureHanfuSchema();
$mapper = new OfferEavMapper();

$axisCodes = ['color', 'size', 'style_type', 'character', 'look_ref', 'prop'];

/**
 * @return list<array{product_id:int,offer_id:string,name:string,tech_n:int,axis_n:int}>
 */
$findDeficient = static function (PDO $pdo, string $prefix, int $websiteId) use ($axisCodes): array {
    $axisList = "'" . implode("','", $axisCodes) . "'";
    $sql = <<<SQL
WITH pub AS (
  SELECT DISTINCT product_id
  FROM {$prefix}product_ws_{$websiteId}_offer
  WHERE status = 'published'
),
codes AS (
  SELECT a.entity_id AS product_id, a.attribute_code
  FROM {$prefix}product_ws_{$websiteId}_attribute_value a
  JOIN pub p ON p.product_id = a.entity_id
  WHERE a.locale = '' AND a.cleared = 0
  GROUP BY 1, 2
),
agg AS (
  SELECT
    product_id,
    COUNT(*) FILTER (
      WHERE attribute_code LIKE 'hanfu_%'
         OR attribute_code IN ('material')
    ) AS tech_n,
    COUNT(*) FILTER (
      WHERE attribute_code IN ({$axisList})
    ) AS axis_n
  FROM codes
  GROUP BY product_id
)
SELECT
  a.product_id,
  COALESCE((
    SELECT value_text
    FROM {$prefix}product_ws_{$websiteId}_attribute_value
    WHERE entity_id = a.product_id AND attribute_code = 'source_offer_id' AND locale = ''
    LIMIT 1
  ), '') AS offer_id,
  COALESCE((
    SELECT LEFT(value_text, 80)
    FROM {$prefix}product_ws_{$websiteId}_attribute_value
    WHERE entity_id = a.product_id AND attribute_code = 'name' AND locale = ''
    LIMIT 1
  ), '') AS name,
  a.tech_n,
  a.axis_n
FROM agg a
WHERE a.tech_n = 0
ORDER BY a.product_id
SQL;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

/**
 * @return array{path:string,offer:array<string,mixed>,source:array<string,mixed>,snapshot:array<string,mixed>,spec_n:int}|null
 */
$findBestSnapshot = static function (string $root, string $offerId): ?array {
    if ($offerId === '' || !is_dir($root)) {
        return null;
    }
    $best = null;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getFilename() !== 'crawl-snapshot.json') {
            continue;
        }
        $raw = @file_get_contents($file->getPathname());
        if ($raw === false || $raw === '') {
            continue;
        }
        $snapshot = json_decode($raw, true);
        if (!is_array($snapshot)) {
            continue;
        }
        $sources = is_array($snapshot['sources'] ?? null) ? $snapshot['sources'] : [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach (is_array($source['offers'] ?? null) ? $source['offers'] : [] as $offer) {
                if (!is_array($offer) || (string)($offer['offer_id'] ?? '') !== $offerId) {
                    continue;
                }
                $specs = is_array($offer['specifications'] ?? null) ? $offer['specifications'] : [];
                $nonAxis = 0;
                foreach ($specs as $name => $_) {
                    $name = (string)$name;
                    if (preg_match('/颜色|色系|colour|color|尺码|尺寸|身高|size|款式|类型|组合|style|type/u', $name) === 1
                        && preg_match('/货源类型|货源类别/u', $name) !== 1
                    ) {
                        continue;
                    }
                    ++$nonAxis;
                }
                $candidate = [
                    'path' => $file->getPathname(),
                    'offer' => $offer,
                    'source' => $source,
                    'snapshot' => $snapshot,
                    'spec_n' => $nonAxis,
                ];
                if ($best === null || $candidate['spec_n'] > $best['spec_n']) {
                    $best = $candidate;
                }
            }
        }
    }

    return $best;
};

/**
 * @param array<string,mixed> $offer
 * @param array<string,mixed> $source
 * @param array<string,mixed> $snapshot
 * @return list<array<string,mixed>>
 */
$buildWriteRows = static function (
    OfferEavMapper $mapper,
    ProductCatalogEavBootstrap $eavBootstrap,
    ProductAttributeMetadataCatalog $attributeMetadata,
    array $offer,
    array $source,
    array $snapshot,
    int $productId,
    array $axisCodes,
): array {
    $catalog = $mapper->catalog($offer, 'REMEDIATE');
    $nonVariantDefinitions = [];
    foreach (is_array($catalog['definitions'] ?? null) ? $catalog['definitions'] : [] as $definition) {
        if (!is_array($definition) || !empty($definition['variant'])) {
            continue;
        }
        $code = strtolower(trim((string)($definition['code'] ?? '')));
        if ($code === '' || in_array($code, $axisCodes, true)) {
            continue;
        }
        $nonVariantDefinitions[] = $definition;
    }
    if ($nonVariantDefinitions === []) {
        return [];
    }
    $eavBootstrap->ensureHanfuAttributeOptions($nonVariantDefinitions);
    $valueKeys = [];
    foreach ($nonVariantDefinitions as $definition) {
        $valueKeys[(string)$definition['code']] = true;
    }
    $alignedCatalog = hanfu1688AlignProductValuesToSharedEav($attributeMetadata, [
        'definitions' => $nonVariantDefinitions,
        'product_values' => array_intersect_key(
            is_array($catalog['product_values'] ?? null) ? $catalog['product_values'] : [],
            $valueKeys,
        ),
    ]);
    $rows = $mapper->map(
        $offer,
        $source,
        (string)($snapshot['snapshot_digest'] ?? hash('sha256', (string)($offer['offer_id'] ?? ''))),
        [
            'definitions' => $nonVariantDefinitions,
            'product_values' => $alignedCatalog['product_values'] ?? [],
        ],
    );
    $writeRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
        if ($code === '' || str_starts_with($code, 'source_')) {
            continue;
        }
        if (in_array($code, $axisCodes, true)) {
            continue;
        }
        $writeRows[] = $row;
    }

    return $attributeMetadata->normalizeRows($writeRows, $productId);
};

/**
 * @param list<array<string,mixed>> $writeRows
 */
$applyRows = static function (
    AttributeValueRepository $attributes,
    int $websiteId,
    int $productId,
    array $writeRows,
): void {
    foreach ($writeRows as $row) {
        foreach (['', 'zh_Hans_CN'] as $locale) {
            $attributes->writeTyped(
                $websiteId,
                0,
                'product',
                $productId,
                (string)$row['attribute_code'],
                $locale,
                (string)$row['value_type'],
                $row['value'] ?? null,
                false,
            );
        }
    }
};

if ($scan) {
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
    $prefix = (string)($db['prefix'] ?? '');
    $deficient = $findDeficient($pdo, $prefix, $websiteId);
    echo "deficient=" . count($deficient) . " website={$websiteId}\n";
    $fixed = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($deficient as $row) {
        $pid = (int)$row['product_id'];
        $oid = trim((string)$row['offer_id']);
        $name = trim((string)$row['name']);
        echo "\n#{$pid} offer={$oid} name={$name}\n";
        if ($oid === '') {
            echo "  skip: no source_offer_id\n";
            ++$skipped;
            continue;
        }
        $hit = $findBestSnapshot($snapshotRoot, $oid);
        if ($hit === null || $hit['spec_n'] <= 0) {
            echo "  skip: no listing specs in snapshots under {$snapshotRoot}\n";
            ++$skipped;
            continue;
        }
        echo "  snapshot={$hit['path']} listing_specs={$hit['spec_n']}\n";
        try {
            $writeRows = $buildWriteRows(
                $mapper,
                $eavBootstrap,
                $attributeMetadata,
                $hit['offer'],
                $hit['source'],
                $hit['snapshot'],
                $pid,
                $axisCodes,
            );
        } catch (Throwable $e) {
            echo "  fail: " . $e->getMessage() . "\n";
            ++$failed;
            continue;
        }
        if ($writeRows === []) {
            echo "  skip: mapper produced no non-axis rows\n";
            ++$skipped;
            continue;
        }
        echo "  rows=" . count($writeRows) . "\n";
        foreach ($writeRows as $writeRow) {
            $value = $writeRow['value'] ?? null;
            echo sprintf(
                "    %s (%s) = %s\n",
                (string)$writeRow['attribute_code'],
                (string)$writeRow['value_type'],
                is_scalar($value) || $value === null
                    ? (string)$value
                    : json_encode($value, JSON_UNESCAPED_UNICODE),
            );
        }
        if (!$apply) {
            continue;
        }
        try {
            $applyRows($attributes, $websiteId, $pid, $writeRows);
            echo "  applied\n";
            ++$fixed;
        } catch (Throwable $e) {
            echo "  fail-apply: " . $e->getMessage() . "\n";
            ++$failed;
        }
    }
    echo "\nsummary fixed={$fixed} skipped={$skipped} failed={$failed} mode=" . ($apply ? 'apply' : 'dry-run') . "\n";
    exit($failed > 0 ? 1 : 0);
}

if ($productId <= 0 || $offerId === '' || $snapshotPath === '') {
    fwrite(STDERR, "Required: --scan, or --product-id= --offer-id= --snapshot= plus --dry-run|--apply\n");
    exit(2);
}
if (!is_file($snapshotPath)) {
    fwrite(STDERR, "Snapshot not found: {$snapshotPath}\n");
    exit(2);
}

$snapshot = json_decode((string)file_get_contents($snapshotPath), true);
if (!is_array($snapshot)) {
    fwrite(STDERR, "Snapshot JSON invalid\n");
    exit(2);
}
$offer = null;
$source = is_array($snapshot['sources'][0] ?? null) ? $snapshot['sources'][0] : [];
foreach (is_array($source['offers'] ?? null) ? $source['offers'] : [] as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }
    if ((string)($candidate['offer_id'] ?? '') === $offerId) {
        $offer = $candidate;
        break;
    }
}
if ($offer === null) {
    fwrite(STDERR, "Offer {$offerId} not found in snapshot\n");
    exit(2);
}

$writeRows = $buildWriteRows(
    $mapper,
    $eavBootstrap,
    $attributeMetadata,
    $offer,
    $source,
    $snapshot,
    $productId,
    $axisCodes,
);
if ($writeRows === []) {
    fwrite(STDERR, "No non-axis listing attributes to backfill\n");
    exit(1);
}

echo "product_id={$productId} offer_id={$offerId} website={$websiteId}\n";
echo "rows=" . count($writeRows) . "\n";
foreach ($writeRows as $row) {
    $value = $row['value'] ?? null;
    echo sprintf(
        "  %s (%s) = %s\n",
        (string)$row['attribute_code'],
        (string)$row['value_type'],
        is_scalar($value) || $value === null
            ? (string)$value
            : json_encode($value, JSON_UNESCAPED_UNICODE),
    );
}

if (!$apply) {
    echo "dry-run only; pass --apply to write\n";
    exit(0);
}

$applyRows($attributes, $websiteId, $productId, $writeRows);
echo "applied\n";
