<?php

declare(strict_types=1);

/**
 * Backfill product weight_kg from 1688 source_url (unitWeight).
 *
 * Rate-limit hard: PublicHttpClient detail delay + inter-offer sleep.
 * Prefer --limit / --product-id; do not blast the full catalog in one run.
 * When HTTP hits 1688 captcha/login wall, stop and use --plan-only + Browser
 * extract + --weights-json=... --apply (or --set-weight= for a single product).
 *
 * Usage:
 *   php .../remediate-product-weights-from-source.php --dry-run --plan-only --limit=20
 *   php .../remediate-product-weights-from-source.php --apply --product-id=192
 *   php .../remediate-product-weights-from-source.php --apply --product-id=192 --set-weight=0.45
 *   php .../remediate-product-weights-from-source.php --apply --weights-json=/tmp/weights.json
 *   php .../remediate-product-weights-from-source.php --apply --limit=5 --delay-ms=8000 --jitter-ms=2000
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Sample\Hanfu1688\OfferDetailParser;
use Weline\Product\Sample\Hanfu1688\PublicHttpClient;

$modes = array_values(array_intersect($argv, ['--dry-run', '--apply']));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run or --apply.\n");
    exit(2);
}
$apply = $modes[0] === '--apply';

$websiteId = 0;
$limit = 5;
$offset = 0;
$delayMs = 8_000;
$jitterMs = 2_000;
$detailDelayMs = 4_000;
$minDelayMs = 1_200;
$maxAttempts = 2;
$productIdFilter = 0;
$stopOnBan = true;
$planOnly = false;
$setWeight = null;
$weightsJsonPath = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--website=')) {
        $websiteId = max(0, (int)substr($arg, 10));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(50, (int)substr($arg, 8)));
    } elseif (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int)substr($arg, 9));
    } elseif (str_starts_with($arg, '--delay-ms=')) {
        $delayMs = max(3_000, (int)substr($arg, 11));
    } elseif (str_starts_with($arg, '--jitter-ms=')) {
        $jitterMs = max(0, (int)substr($arg, 12));
    } elseif (str_starts_with($arg, '--detail-delay-ms=')) {
        $detailDelayMs = max(2_000, (int)substr($arg, 18));
    } elseif (str_starts_with($arg, '--product-id=')) {
        $productIdFilter = max(0, (int)substr($arg, 13));
    } elseif (str_starts_with($arg, '--set-weight=')) {
        $setWeight = (float)substr($arg, 13);
    } elseif (str_starts_with($arg, '--weights-json=')) {
        $weightsJsonPath = trim(substr($arg, 15));
    } elseif ($arg === '--plan-only') {
        $planOnly = true;
    } elseif ($arg === '--no-stop-on-ban') {
        $stopOnBan = false;
    }
}

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
$parser = new OfferDetailParser();
$http = new PublicHttpClient(
    null,
    null,
    $minDelayMs,
    8_388_608,
    $detailDelayMs,
);

/**
 * @return list<array{product_id:int,sku:string,source_url:string,weight_kg:float}>
 */
function collectCandidates(
    ProductRepository $products,
    AttributeValueRepository $attributes,
    int $websiteId,
    int $productIdFilter,
    int $limit,
    int $offset,
): array {
    $rows = $products->listAll($websiteId);
    $out = [];
    $skipped = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $productId = (int)($row[Product::schema_fields_ID] ?? $row['product_id'] ?? $row['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        if ($productIdFilter > 0 && $productId !== $productIdFilter) {
            continue;
        }
        $status = strtolower(trim((string)($row[Product::schema_fields_STATUS] ?? $row['status'] ?? '')));
        if ($status !== Product::STATUS_PUBLISHED) {
            continue;
        }
        $source = trim((string)$attributes->read($websiteId, 0, 'product', $productId, 'source_url', '', [''])->value);
        if ($source === '' || !str_contains($source, '1688.com/offer/')) {
            continue;
        }
        $weightRaw = $attributes->read($websiteId, 0, 'product', $productId, 'weight_kg', '', [''])->value;
        $weight = is_numeric($weightRaw) ? (float)$weightRaw : 0.0;
        if ($weight > 0) {
            continue;
        }
        if ($skipped < $offset) {
            ++$skipped;
            continue;
        }
        $out[] = [
            'product_id' => $productId,
            'sku' => (string)($row[Product::schema_fields_SKU] ?? $row['sku'] ?? ''),
            'source_url' => $source,
            'weight_kg' => $weight,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function offerIdFromSourceUrl(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)) {
        throw new InvalidArgumentException('hanfu_1688_offer_url_invalid');
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($host, ['detail.1688.com', 'm.1688.com'], true)) {
        throw new InvalidArgumentException('hanfu_1688_offer_url_invalid');
    }
    if (preg_match('#^/offer/([1-9][0-9]{0,20})\.html$#D', (string)($parts['path'] ?? ''), $match) !== 1) {
        throw new InvalidArgumentException('hanfu_1688_offer_url_invalid');
    }

    return $match[1];
}

/**
 * Walk MTop payloads for unitWeight (kg). Desktop HTML remains the preferred source.
 */
function digUnitWeightKg(mixed $node, int $depth = 0): ?float
{
    if ($depth > 12) {
        return null;
    }
    if (is_array($node)) {
        foreach (['unitWeight', 'unit_weight', 'weight'] as $key) {
            if (array_key_exists($key, $node) && is_numeric($node[$key])) {
                $value = (float)$node[$key];
                if ($value >= 0.05 && $value < 500) {
                    return $value;
                }
            }
        }
        foreach ($node as $child) {
            $found = digUnitWeightKg($child, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * @return array{weight_kg:?float,method:string,error?:string}
 */
function fetchWeightKg(PublicHttpClient $http, OfferDetailParser $parser, string $offerId): array
{
    $desktopUrl = 'https://detail.1688.com/offer/' . rawurlencode($offerId) . '.html';
    $mobileUrl = 'https://m.1688.com/offer/' . rawurlencode($offerId) . '.html';
    $lastError = 'unknown';

    // Desktop context has productPackInfo.unitWeight; mobile component parse always null weight.
    try {
        $html = $http->get($desktopUrl);
        $detail = $parser->parse($html, $desktopUrl);
        $weight = $detail['weight_kg'] ?? null;
        if (is_numeric($weight) && (float)$weight > 0) {
            return ['weight_kg' => (float)$weight, 'method' => 'desktop_html'];
        }
        if (is_numeric($weight) && (float)$weight <= 0) {
            return ['weight_kg' => null, 'method' => 'desktop_html', 'error' => 'source_weight_empty'];
        }
        $lastError = 'desktop_weight_missing';
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    try {
        $payload = $http->offerDetail($offerId);
        $detail = $parser->parseMtop($payload, $mobileUrl);
        $weight = $detail['weight_kg'] ?? digUnitWeightKg($payload);
        if (is_numeric($weight) && (float)$weight > 0) {
            return ['weight_kg' => (float)$weight, 'method' => 'mtop'];
        }
        $lastError = 'mtop_weight_missing';
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    return ['weight_kg' => null, 'method' => 'none', 'error' => $lastError];
}

function looksLikeBan(string $error): bool
{
    $error = strtolower($error);
    foreach ([
        'forbidden',
        'access denied',
        'captcha',
        'punish',
        'deny',
        '429',
        '403',
        '限流',
        '验证',
        '风控',
        'blocked',
        'validation_required',
        'login_jump',
        'x5sec',
        '_____tmd_____',
        'offer_page_blocked',
        'token_missing',
    ] as $needle) {
        if (str_contains($error, $needle)) {
            return true;
        }
    }

    return false;
}

function sleepBetweenOffers(int $delayMs, int $jitterMs): void
{
    $extra = $jitterMs > 0 ? random_int(0, $jitterMs) : 0;
    $total = max(0, $delayMs + $extra);
    if ($total > 0) {
        usleep($total * 1_000);
    }
}

/**
 * @param array{product_id:int,sku?:string,source_url?:string,weight_kg:float,method?:string} $item
 * @return array<string, mixed>
 */
function writeWeightRow(
    AttributeValueRepository $attributes,
    int $websiteId,
    array $item,
    bool $apply,
): array {
    $weightKg = round((float)$item['weight_kg'], 3);
    $row = [
        'product_id' => (int)$item['product_id'],
        'sku' => (string)($item['sku'] ?? ''),
        'source_url' => (string)($item['source_url'] ?? ''),
        'weight_kg' => $weightKg,
        'weight_minor' => (int)max(1, (int)round($weightKg * 1000)),
        'method' => (string)($item['method'] ?? 'manual'),
    ];
    if ($weightKg < 0.05) {
        $row['status'] = 'skipped';
        $row['error'] = 'source_weight_empty_or_placeholder';
        return $row;
    }
    if (!$apply) {
        $row['status'] = 'dry-run';
        return $row;
    }
    $attributes->writeTyped(
        $websiteId,
        0,
        'product',
        (int)$item['product_id'],
        'weight_kg',
        '',
        'number',
        $weightKg,
        false,
    );
    $row['status'] = 'updated';

    return $row;
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'limit' => $limit,
    'offset' => $offset,
    'delay_ms' => $delayMs,
    'jitter_ms' => $jitterMs,
    'detail_delay_ms' => $detailDelayMs,
    'plan_only' => $planOnly,
    'candidates' => 0,
    'updated' => 0,
    'skipped' => 0,
    'failed' => 0,
    'stopped' => false,
    'rows' => [],
];

if ($weightsJsonPath !== '') {
    if (!is_file($weightsJsonPath)) {
        fwrite(STDERR, "weights-json not found: {$weightsJsonPath}\n");
        exit(2);
    }
    $decoded = json_decode((string)file_get_contents($weightsJsonPath), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "weights-json invalid JSON.\n");
        exit(2);
    }
    $items = isset($decoded[0]) || $decoded === [] ? $decoded : ($decoded['rows'] ?? $decoded['items'] ?? []);
    if (!is_array($items)) {
        fwrite(STDERR, "weights-json must be a list of {product_id,weight_kg}.\n");
        exit(2);
    }
    foreach ($items as $item) {
        if (!is_array($item) || (int)($item['product_id'] ?? 0) <= 0 || !isset($item['weight_kg'])) {
            $summary['failed']++;
            continue;
        }
        $row = writeWeightRow($attributes, $websiteId, [
            'product_id' => (int)$item['product_id'],
            'sku' => (string)($item['sku'] ?? ''),
            'source_url' => (string)($item['source_url'] ?? ''),
            'weight_kg' => (float)$item['weight_kg'],
            'method' => (string)($item['method'] ?? 'weights_json'),
        ], $apply);
        if ($row['status'] === 'updated') {
            $summary['updated']++;
        } elseif ($row['status'] === 'dry-run' || $row['status'] === 'skipped') {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }
        $summary['rows'][] = $row;
        fwrite(STDOUT, json_encode(['phase' => 'row', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    $summary['candidates'] = count($summary['rows']);
    fwrite(STDOUT, json_encode(['phase' => 'summary', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(($summary['failed'] > 0 || $summary['stopped']) ? 1 : 0);
}

if ($setWeight !== null) {
    if ($productIdFilter <= 0) {
        fwrite(STDERR, "--set-weight requires --product-id=.\n");
        exit(2);
    }
    $row = writeWeightRow($attributes, $websiteId, [
        'product_id' => $productIdFilter,
        'weight_kg' => (float)$setWeight,
        'method' => 'set_weight',
    ], $apply);
    if ($row['status'] === 'updated') {
        $summary['updated']++;
    } elseif ($row['status'] === 'dry-run' || $row['status'] === 'skipped') {
        $summary['skipped']++;
    } else {
        $summary['failed']++;
    }
    $summary['candidates'] = 1;
    $summary['rows'][] = $row;
    fwrite(STDOUT, json_encode(['phase' => 'row', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, json_encode(['phase' => 'summary', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(($summary['failed'] > 0 || $summary['stopped']) ? 1 : 0);
}

$candidates = collectCandidates($products, $attributes, $websiteId, $productIdFilter, $limit, $offset);
$summary['candidates'] = count($candidates);

fwrite(STDOUT, json_encode([
    'phase' => 'plan',
    'candidates' => array_map(static fn(array $c): array => [
        'product_id' => $c['product_id'],
        'sku' => $c['sku'],
        'source_url' => $c['source_url'],
    ], $candidates),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($planOnly) {
    fwrite(STDOUT, json_encode(['phase' => 'summary', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
}

foreach ($candidates as $index => $candidate) {
    $row = [
        'product_id' => $candidate['product_id'],
        'sku' => $candidate['sku'],
        'source_url' => $candidate['source_url'],
        'status' => 'pending',
    ];
    try {
        $offerId = offerIdFromSourceUrl($candidate['source_url']);
        $row['offer_id'] = $offerId;
        if ($index > 0) {
            sleepBetweenOffers($delayMs, $jitterMs);
        }
        $fetched = null;
        $lastError = '';
        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            if ($attempt > 1) {
                sleepBetweenOffers($delayMs * $attempt, $jitterMs);
            }
            $fetched = fetchWeightKg($http, $parser, $offerId);
            if (($fetched['weight_kg'] ?? null) !== null) {
                break;
            }
            $lastError = (string)($fetched['error'] ?? 'weight_missing');
            if ($lastError === 'source_weight_empty') {
                break;
            }
            if ($stopOnBan && looksLikeBan($lastError)) {
                $row['status'] = 'banned';
                $row['error'] = $lastError;
                $summary['failed']++;
                $summary['rows'][] = $row;
                $summary['stopped'] = true;
                fwrite(STDOUT, json_encode(['phase' => 'stop_ban', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                break 2;
            }
        }
        $weightKg = is_array($fetched) ? ($fetched['weight_kg'] ?? null) : null;
        if (!is_numeric($weightKg) || (float)$weightKg <= 0) {
            $error = $lastError !== '' ? $lastError : (string)($fetched['error'] ?? 'weight_missing');
            $row['status'] = $error === 'source_weight_empty' ? 'skipped' : 'failed';
            $row['error'] = $error;
            if ($row['status'] === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
            $summary['rows'][] = $row;
            fwrite(STDOUT, json_encode(['phase' => 'row', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            continue;
        }
        $row = writeWeightRow($attributes, $websiteId, [
            'product_id' => $candidate['product_id'],
            'sku' => $candidate['sku'],
            'source_url' => $candidate['source_url'],
            'weight_kg' => (float)$weightKg,
            'method' => (string)($fetched['method'] ?? ''),
        ], $apply);
        if ($row['status'] === 'updated') {
            $summary['updated']++;
        } else {
            $summary['skipped']++;
        }
    } catch (Throwable $e) {
        $row['status'] = 'failed';
        $row['error'] = $e->getMessage();
        $summary['failed']++;
        if ($stopOnBan && looksLikeBan($e->getMessage())) {
            $summary['stopped'] = true;
            $summary['rows'][] = $row;
            fwrite(STDOUT, json_encode(['phase' => 'stop_ban', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            break;
        }
    }
    $summary['rows'][] = $row;
    fwrite(STDOUT, json_encode(['phase' => 'row', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

fwrite(STDOUT, json_encode(['phase' => 'summary', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit(($summary['failed'] > 0 || $summary['stopped']) ? 1 : 0);
