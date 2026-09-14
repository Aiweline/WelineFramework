<?php
/**
 * Fixture for FAQ PDP resolve/merge e2e chapter (pure cascade, no app bootstrap).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 7);
require_once $root . '/vendor/autoload.php';

use Weline\Faq\Model\FaqItem;
use Weline\Faq\Service\FaqPdpResolveService;
use Weline\Faq\Service\FaqTemplatePacks;

$svc = new FaqPdpResolveService();

$row = static function (
    string $key,
    string $q,
    int $websiteId,
    string $store,
    string $channel,
    int $sort,
    string $status = FaqItem::STATUS_ENABLED,
): array {
    return [
        'faq_id' => abs(crc32($key . $websiteId . $store . $channel)) % 100000,
        'faq_key' => $key,
        'question' => $q,
        'answer' => $q . '-a',
        'website_id' => $websiteId,
        'store_code' => $store,
        'channel_code' => $channel,
        'locale_code' => '',
        'sort_order' => $sort,
        'status' => $status,
    ];
};

$defaults = $svc->cascadeRows([
    $row('shipping', '网站运费', 0, '', '', 1),
    $row('shipping', '店铺运费', 1, 'main', '', 1),
    $row('returns', '退换', 0, '', '', 2),
], 1, 'main', 'app');

$products = $svc->cascadeRows([
    $row('material', '材质', 1, '', '', 1),
], 1, 'main', 'app', '', FaqPdpResolveService::SOURCE_PRODUCT);

$merged = $svc->mergeSets($defaults, $products, true);
$mergeOff = $svc->mergeSets($defaults, $products, false);
$suppressed = $svc->cascadeRows([
    $row('shipping', '网站运费', 0, '', '', 1),
    $row('shipping', '隐藏', 1, 'main', '', 1, FaqItem::STATUS_DISABLED),
], 1, 'main', '');

$result = [
    'ok' => false,
    'chapter' => 'e2e-resolve-merge',
    'packs' => FaqTemplatePacks::codes(),
    'defaults_count' => count($defaults),
    'shipping_question' => $defaults[0]['question'] ?? null,
    'shipping_source' => $defaults[0]['source'] ?? null,
    'merged_keys' => array_column($merged, 'faq_key'),
    'merge_off_first' => $mergeOff[0]['faq_key'] ?? null,
    'suppressed_has_shipping' => in_array('shipping', array_column($suppressed, 'faq_key'), true),
];

$result['ok'] = $result['shipping_question'] === '店铺运费'
    && $result['shipping_source'] === FaqPdpResolveService::SOURCE_STORE
    && $result['merged_keys'] === ['shipping', 'returns', 'material']
    && $result['merge_off_first'] === 'material'
    && $result['suppressed_has_shipping'] === false;

echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
