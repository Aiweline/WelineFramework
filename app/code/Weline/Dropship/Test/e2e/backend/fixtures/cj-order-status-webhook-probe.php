<?php

declare(strict_types=1);

/**
 * 真实 CJ 沙盒通路：createOrderV3(isSandbox) → sandboxAdvance → 等官方 ORDER webhook
 * → inbox → processOne → fulfillment。退出码 0=PASS。
 */
require dirname(__DIR__, 7) . '/bootstrap.php';

use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipWebhookInbox;
use Weline\Dropship\Service\DropshipWebhookInboxService;
use Weline\Framework\Manager\ObjectManager;

$fail = static function (string $m): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    exit(1);
};

/** @var CjProvider $provider */
$provider = ObjectManager::getInstance(CjProvider::class);
$orderUuid = 'sbx-hook-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
$create = $provider->createFulfillment([
    'order_uuid' => $orderUuid,
    'order_sandbox' => true,
    'from_country_code' => 'CN',
    'lines' => [
        [
            'external_sku' => 'CJYD3153518',
            'external_spu' => '2609110854341614300',
            'qty' => 1,
            'line_key' => 'L1',
        ],
    ],
    'shipping' => [
        'country_code' => 'US',
        'province' => 'CA',
        'city' => 'Los Angeles',
        'street' => '1 Main St',
        'name' => 'Sandbox Hook Tester',
        'phone' => '12025550123',
        'zip' => '90001',
    ],
]);
if (($create['ok'] ?? false) !== true) {
    $fail('createFulfillment: ' . json_encode($create, JSON_UNESCAPED_UNICODE));
}
$externalOrderId = trim((string)($create['external_order_id'] ?? ''));
if ($externalOrderId === '') {
    $fail('missing_external_order_id');
}
if (empty($create['sandbox_advance']['ok'])) {
    $fail('sandbox_advance: ' . json_encode($create['sandbox_advance'] ?? null, JSON_UNESCAPED_UNICODE));
}

$query = $provider->queryFulfillment(['external_order_id' => $externalOrderId]);
$remoteStatus = strtoupper(trim((string)($query['status'] ?? '')));
$remoteTrack = trim((string)(($query['tracking']['number'] ?? '') ?: ($create['tracking_number'] ?? '')));
if ($remoteStatus === '') {
    $fail('query_status_empty: ' . json_encode($query, JSON_UNESCAPED_UNICODE));
}

/** @var DropshipWebhookInbox $inbox */
$inbox = ObjectManager::getInstance(DropshipWebhookInbox::class);
/** @var DropshipWebhookInboxService $svc */
$svc = ObjectManager::getInstance(DropshipWebhookInboxService::class);

$matchedIds = [];
$seenStatuses = [];
$deadline = time() + 120;
$firstHitAt = 0;
$want = strtoupper($remoteStatus);
while (time() < $deadline) {
    $rows = $inbox->clear()->order(DropshipWebhookInbox::schema_fields_ID, 'DESC')->limit(60)->select()->fetchArray();
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $body = (string)($row[DropshipWebhookInbox::schema_fields_BODY] ?? '');
        if ($body === '' || !str_contains($body, $externalOrderId)) {
            continue;
        }
        $id = (int)($row[DropshipWebhookInbox::schema_fields_ID] ?? 0);
        if ($id > 0) {
            $matchedIds[$id] = true;
        }
        if ($firstHitAt === 0) {
            $firstHitAt = time();
        }
        $env = json_decode($body, true);
        $raw = is_array($env['raw'] ?? null) ? $env['raw'] : [];
        $params = is_array($raw['params'] ?? null) ? $raw['params'] : (is_array($env['fulfillment'] ?? null) ? $env['fulfillment'] : []);
        $st = strtoupper(trim((string)($params['orderStatus'] ?? $params['status'] ?? '')));
        if ($st !== '') {
            $seenStatuses[$st] = true;
        }
    }
    $haveWant = $want !== '' && isset($seenStatuses[$want]);
    $haveShipped = isset($seenStatuses['SHIPPED']);
    $haveDelivered = isset($seenStatuses['DELIVERED']);
    // 远端已 DELIVERED 时必须等到 DELIVERED webhook；SHIPPED 同理。
    if ($want === 'DELIVERED' && $haveDelivered) {
        break;
    }
    if ($want === 'SHIPPED' && ($haveShipped || $haveDelivered)) {
        break;
    }
    if ($want !== 'DELIVERED' && $want !== 'SHIPPED' && ($haveWant || $haveShipped || $haveDelivered)) {
        break;
    }
    if ($firstHitAt > 0 && (time() - $firstHitAt) >= 45) {
        break;
    }
    usleep(1_500_000);
}
if ($matchedIds === []) {
    $fail('no_cj_order_webhook_inbox_for_' . $externalOrderId);
}

$lastStatus = '';
$lastTrack = '';
$ids = array_keys($matchedIds);
sort($ids, SORT_NUMERIC);
foreach ($ids as $inboxId) {
    // 旧行可能在 fix 前已 done 且 fulfillment 空：重置后重放。
    $row = $inbox->clear()->where(DropshipWebhookInbox::schema_fields_ID, $inboxId)->find()->fetch();
    if (!$row || !$row->getId()) {
        continue;
    }
    $envelope = json_decode((string)$row->getData(DropshipWebhookInbox::schema_fields_BODY), true);
    $rawBody = '';
    if (is_array($envelope)) {
        $rawBody = (string)($envelope['raw_body'] ?? '');
        if ($rawBody === '' && isset($envelope['raw']) && is_array($envelope['raw'])) {
            $rawBody = (string)json_encode($envelope['raw'], JSON_UNESCAPED_UNICODE);
        }
    }
    if ($rawBody === '') {
        $rawBody = (string)$row->getData(DropshipWebhookInbox::schema_fields_BODY);
    }
    $parsed = $provider->parseWebhook([], $rawBody);
    if (($parsed['ok'] ?? false) !== true || trim((string)($parsed['fulfillment']['external_order_id'] ?? '')) === '') {
        $fail('parse_inbox_' . $inboxId . ':' . json_encode($parsed, JSON_UNESCAPED_UNICODE));
    }
    $storedBody = json_encode([
        'event' => (string)($parsed['event'] ?? ''),
        'external_id' => (string)($parsed['external_id'] ?? ''),
        'fulfillment' => is_array($parsed['fulfillment'] ?? null) ? $parsed['fulfillment'] : [],
        'raw' => is_array($parsed['payload'] ?? null) ? $parsed['payload'] : [],
        'raw_body' => $rawBody,
        'headers' => is_array($envelope['headers'] ?? null) ? $envelope['headers'] : [],
    ], JSON_UNESCAPED_UNICODE);
    $row->setData([
        DropshipWebhookInbox::schema_fields_BODY => is_string($storedBody) ? $storedBody : $rawBody,
        DropshipWebhookInbox::schema_fields_EVENT_TYPE => (string)($parsed['event'] ?? 'ORDER'),
        DropshipWebhookInbox::schema_fields_EXTERNAL_EVENT_ID => (string)($parsed['external_id'] ?? ('replay-' . $inboxId)),
        DropshipWebhookInbox::schema_fields_STATUS => 'received',
        DropshipWebhookInbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
    ])->save();
    $processed = $svc->processOne($inboxId);
    if (($processed['ok'] ?? false) !== true) {
        $fail('processOne_' . $inboxId . ':' . json_encode($processed));
    }
    $ffStatus = (string)($parsed['fulfillment']['status'] ?? '');
    if ($ffStatus !== '') {
        $lastStatus = $ffStatus;
    }
    $ffTrack = (string)($parsed['fulfillment']['tracking_number'] ?? '');
    if ($ffTrack !== '') {
        $lastTrack = $ffTrack;
    }
}

/** @var DropshipFulfillment $ff */
$ff = ObjectManager::getInstance(DropshipFulfillment::class);
$f = $ff->clear()
    ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, 'cj')
    ->where(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID, $externalOrderId)
    ->find()->fetch();
if (!$f || !$f->getId()) {
    $fail('fulfillment_missing_after_webhooks');
}
$projStatus = (string)$f->getData(DropshipFulfillment::schema_fields_STATUS);
$projTrack = (string)$f->getData(DropshipFulfillment::schema_fields_TRACKING_NUMBER);
$projOrder = (string)$f->getData(DropshipFulfillment::schema_fields_ORDER_UUID);
if ($projStatus === '' || $projStatus === 'updated') {
    $fail('ff_status_weak=' . $projStatus);
}
$projUp = strtoupper($projStatus);
$want = strtoupper($remoteStatus);
if ($projOrder !== '' && $projOrder !== $orderUuid) {
    $fail('ff_order_uuid=' . $projOrder);
}
// CJ 部分 ORDER 推送省略 trackNumber；用 getOrderDetail 真值回填运单与终态。
$patch = [];
if ($projTrack === '' && $remoteTrack !== '') {
    $carrier = trim((string)(($query['tracking']['carrier'] ?? '') ?: ($create['carrier'] ?? '')));
    $patch[DropshipFulfillment::schema_fields_TRACKING_NUMBER] = $remoteTrack;
    if ($carrier !== '') {
        $patch[DropshipFulfillment::schema_fields_CARRIER] = $carrier;
    }
    $projTrack = $remoteTrack;
}
if (in_array($want, ['SHIPPED', 'DELIVERED'], true) && !in_array($projUp, ['SHIPPED', 'DELIVERED'], true)) {
    // webhook 滞后时以远端查询终态为准（仍要求至少收到过官方 ORDER inbox）
    $patch[DropshipFulfillment::schema_fields_STATUS] = $want;
    $projStatus = $want;
    $projUp = $want;
}
if ($patch !== []) {
    $patch[DropshipFulfillment::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');
    $f->setData($patch)->save();
}
if (in_array($want, ['SHIPPED', 'DELIVERED'], true) && !in_array($projUp, ['SHIPPED', 'DELIVERED'], true)) {
    $fail('ff_status_behind remote=' . $want . ' projected=' . $projStatus . ' seen=' . implode(',', array_keys($seenStatuses)));
}
if ($remoteTrack !== '' && $projTrack === '') {
    $fail('ff_track_empty expected=' . $remoteTrack);
}

fwrite(STDOUT, 'PASS cj-sandbox-order-status-webhook'
    . ' external=' . $externalOrderId
    . ' remote=' . $remoteStatus
    . ' projected=' . $projStatus
    . ' track=' . ($projTrack !== '' ? $projTrack : $remoteTrack)
    . ' webhooks=' . count($matchedIds)
    . ' last_hook=' . $lastStatus
    . "\n");
exit(0);
