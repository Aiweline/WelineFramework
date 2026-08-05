<?php
declare(strict_types=1);

/**
 * TEST-P2A-06 fixture：Website 父价 + Store cleared 覆盖
 *
 * stdin JSON:
 *   { "action": "prepare"|"restore"|"cleanup", "product_id"?: int, "offer_id"?: int, "website_id"?: int, "store_id"?: int, "currency"?: string }
 * stdout JSON: ok + ids
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P2A06_PRODUCT_BASE = 910600;
const P2A06_CURRENCY = 'CNY';
const P2A06_PARENT_MINOR = 2599;

/**
 * @return array<string, mixed>
 */
function p2a06_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $payload
 */
function p2a06_output(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function p2a06_fail(string $message): never
{
    p2a06_output(['ok' => false, 'error' => $message]);
}

/**
 * @return array{website_id:int,store_id:int,currency:string,product_id:int}
 */
function p2a06_scope(array $input): array
{
    $websiteId = isset($input['website_id']) ? (int)$input['website_id'] : 0;
    $storeId = isset($input['store_id']) ? (int)$input['store_id'] : 1;
    $currency = strtoupper(trim((string)($input['currency'] ?? P2A06_CURRENCY))) ?: P2A06_CURRENCY;
    $productId = isset($input['product_id']) ? (int)$input['product_id'] : (P2A06_PRODUCT_BASE + random_int(1, 899));

    return [
        'website_id' => $websiteId,
        'store_id' => $storeId,
        'currency' => $currency,
        'product_id' => $productId,
    ];
}

function p2a06_delete_prices(PriceRepository $prices, int $websiteId, int $offerId, string $currency): void
{
    foreach ([0, 1, 2, 3, 4, 5] as $storeId) {
        try {
            $prices->deleteOverlay($websiteId, $storeId, $offerId, $currency);
        } catch (Throwable) {
            // best-effort
        }
    }
}

function p2a06_find_offer_by_product(int $websiteId, int $productId): ?Offer
{
    /** @var Offer $offer */
    $offer = ObjectManager::create(Offer::class, [], false);
    $row = $offer->forWebsite($websiteId)
        ->reset()
        ->where(Offer::schema_fields_PRODUCT_ID, $productId)
        ->find()
        ->fetch();
    if ($row instanceof Offer && (int)$row->getId() > 0) {
        return $row;
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function p2a06_prepare(array $input): array
{
    $om = ObjectManager::getInstance();
    $scope = p2a06_scope($input);
    $websiteId = $scope['website_id'];
    $storeId = $scope['store_id'];
    $currency = $scope['currency'];
    $productId = $scope['product_id'];

    /** @var OfferRepository $offers */
    $offers = $om->get(OfferRepository::class);
    /** @var PriceRepository $prices */
    $prices = $om->get(PriceRepository::class);

    $existing = p2a06_find_offer_by_product($websiteId, $productId);
    if ($existing !== null) {
        p2a06_delete_prices($prices, $websiteId, (int)$existing->getId(), $currency);
        $existing->delete();
    }

    $offer = $offers->create($websiteId, [
        Offer::schema_fields_PRODUCT_ID => $productId,
        Offer::schema_fields_GLOBAL_OFFER_UUID => 'e2e-p2a06-' . bin2hex(random_bytes(6)),
        Offer::schema_fields_STATUS => 'published',
    ]);
    $offerId = (int)$offer->getId();
    if ($offerId <= 0) {
        p2a06_fail('offer create failed');
    }

    $prices->writeExplicit($websiteId, 0, $offerId, $currency, P2A06_PARENT_MINOR);
    $prices->writeCleared($websiteId, $storeId, $offerId, $currency);

    try {
        $prices->assertSellable($websiteId, $storeId, $offerId, $currency);
        p2a06_fail('expected assertSellable to throw after writeCleared');
    } catch (\Weline\Product\Service\CatalogConflictException $e) {
        if ($e->errorCode() !== 'price_cleared_at_scope') {
            p2a06_fail('unexpected error_code: ' . $e->errorCode());
        }
    }

    return [
        'ok' => true,
        'action' => 'prepare',
        'website_id' => $websiteId,
        'store_id' => $storeId,
        'currency' => $currency,
        'product_id' => $productId,
        'offer_id' => $offerId,
        'parent_amount_minor' => P2A06_PARENT_MINOR,
        'name' => 'E2E P2A06 Price Cleared',
        'sku' => 'E2E-P2A06-' . $offerId,
        'price' => round(P2A06_PARENT_MINOR / 100, 2),
    ];
}

/**
 * @return array<string, mixed>
 */
function p2a06_restore(array $input): array
{
    $websiteId = (int)($input['website_id'] ?? 0);
    $storeId = (int)($input['store_id'] ?? 1);
    $offerId = (int)($input['offer_id'] ?? 0);
    $currency = strtoupper(trim((string)($input['currency'] ?? P2A06_CURRENCY))) ?: P2A06_CURRENCY;
    if ($offerId <= 0) {
        p2a06_fail('offer_id required for restore');
    }

    /** @var PriceRepository $prices */
    $prices = ObjectManager::getInstance()->get(PriceRepository::class);
    $prices->deleteOverlay($websiteId, $storeId, $offerId, $currency);
    $amount = $prices->assertSellable($websiteId, $storeId, $offerId, $currency);

    return [
        'ok' => true,
        'action' => 'restore',
        'website_id' => $websiteId,
        'store_id' => $storeId,
        'offer_id' => $offerId,
        'currency' => $currency,
        'amount_minor' => $amount,
    ];
}

/**
 * @return array<string, mixed>
 */
function p2a06_clear_store(array $input): array
{
    $websiteId = (int)($input['website_id'] ?? 0);
    $storeId = (int)($input['store_id'] ?? 1);
    $offerId = (int)($input['offer_id'] ?? 0);
    $currency = strtoupper(trim((string)($input['currency'] ?? P2A06_CURRENCY))) ?: P2A06_CURRENCY;
    if ($offerId <= 0) {
        p2a06_fail('offer_id required for clear_store');
    }

    /** @var PriceRepository $prices */
    $prices = ObjectManager::getInstance()->get(PriceRepository::class);
    $prices->writeCleared($websiteId, $storeId, $offerId, $currency);

    try {
        $prices->assertSellable($websiteId, $storeId, $offerId, $currency);
        p2a06_fail('expected assertSellable to throw after clear_store');
    } catch (\Weline\Product\Service\CatalogConflictException $e) {
        if ($e->errorCode() !== 'price_cleared_at_scope') {
            p2a06_fail('unexpected error_code: ' . $e->errorCode());
        }
    }

    return [
        'ok' => true,
        'action' => 'clear_store',
        'website_id' => $websiteId,
        'store_id' => $storeId,
        'offer_id' => $offerId,
        'currency' => $currency,
    ];
}

/**
 * @return array<string, mixed>
 */
function p2a06_cleanup(array $input): array
{
    $websiteId = (int)($input['website_id'] ?? 0);
    $offerId = (int)($input['offer_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $currency = strtoupper(trim((string)($input['currency'] ?? P2A06_CURRENCY))) ?: P2A06_CURRENCY;

    /** @var PriceRepository $prices */
    $prices = ObjectManager::getInstance()->get(PriceRepository::class);

    if ($offerId > 0) {
        p2a06_delete_prices($prices, $websiteId, $offerId, $currency);
        $offer = ObjectManager::create(Offer::class, [], false)
            ->forWebsite($websiteId)
            ->load($offerId);
        if ($offer instanceof Offer && (int)$offer->getId() === $offerId) {
            $offer->delete();
        }
    } elseif ($productId > 0) {
        $existing = p2a06_find_offer_by_product($websiteId, $productId);
        if ($existing !== null) {
            $oid = (int)$existing->getId();
            p2a06_delete_prices($prices, $websiteId, $oid, $currency);
            $existing->delete();
            $offerId = $oid;
        }
    }

    // best-effort: remove orphan smoke rows for known leftover offer ids
    foreach ([900001] as $orphanOfferId) {
        p2a06_delete_prices($prices, 0, $orphanOfferId, $currency);
    }

    return [
        'ok' => true,
        'action' => 'cleanup',
        'website_id' => $websiteId,
        'offer_id' => $offerId,
        'product_id' => $productId,
    ];
}

$input = p2a06_read_input();
$action = strtolower(trim((string)($input['action'] ?? 'prepare')));

try {
    $result = match ($action) {
        'prepare' => p2a06_prepare($input),
        'restore' => p2a06_restore($input),
        'clear_store' => p2a06_clear_store($input),
        'cleanup' => p2a06_cleanup($input),
        default => ['ok' => false, 'error' => 'unknown action: ' . $action],
    };
    p2a06_output($result);
} catch (Throwable $e) {
    p2a06_fail($e->getMessage());
}
