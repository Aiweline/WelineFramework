<?php

declare(strict_types=1);

/**
 * 多 topic webhook 通路：官方文档样例 JSON → CjProvider 解析 → Inbox → processOne。
 * 退出码 0=PASS。不向公网伪造 CJ 推送；样例体来自 CJ 文档字段。
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
/** @var DropshipWebhookInbox $inbox */
$inbox = ObjectManager::getInstance(DropshipWebhookInbox::class);
/** @var DropshipWebhookInboxService $svc */
$svc = ObjectManager::getInstance(DropshipWebhookInboxService::class);
/** @var DropshipFulfillment $ff */
$ff = ObjectManager::getInstance(DropshipFulfillment::class);

$cases = [
    'ORDER' => [
        'body' => [
            'messageId' => 'e2e-order-' . time(),
            'type' => 'ORDER',
            'messageType' => 'UPDATE',
            'params' => [
                'cjOrderId' => 'SD-E2E-TOPIC-ORDER-1',
                'orderNumber' => 'site-e2e-topic-1',
                'orderStatus' => 'SHIPPED',
                'trackNumber' => 'TN-E2E-1',
                'logisticName' => 'CJPacket',
            ],
        ],
        'expect_topic' => 'order',
        'expect_ff' => 'SD-E2E-TOPIC-ORDER-1',
    ],
    'LOGISTIC' => [
        'body' => [
            'messageId' => 'e2e-log-' . time(),
            'type' => 'LOGISTIC',
            'messageType' => 'UPDATE',
            'params' => [
                'orderId' => 'SD-E2E-TOPIC-LOG-1',
                'storeOrderNumbers' => ['site-e2e-log-1'],
                'trackingNumber' => 'CJPKL-E2E',
                'trackingProvider' => 'USPS',
                'trackingStatus' => 0,
            ],
        ],
        'expect_topic' => 'logistics',
        'expect_ff' => 'SD-E2E-TOPIC-LOG-1',
    ],
    'PRODUCT' => [
        'body' => [
            'messageId' => 'e2e-prod-' . time(),
            'type' => 'PRODUCT',
            'messageType' => 'UPDATE',
            'params' => [
                'pid' => 'E2E-PID-TOPIC-1',
                'productSku' => 'E2E-SKU-1',
                'productStatus' => 3,
                'productSellPrice' => 9.9,
            ],
        ],
        'expect_topic' => 'product',
        'expect_ff' => '',
    ],
    'STOCK' => [
        'body' => [
            'messageId' => 'e2e-stock-' . time(),
            'type' => 'STOCK',
            'messageType' => 'UPDATE',
            'params' => [
                'vid1' => [['vid' => 'vid1', 'pid' => 'E2E-PID-STOCK-1', 'storageNum' => 4]],
            ],
        ],
        'expect_topic' => 'stock',
        'expect_ff' => '',
    ],
    'MAKEUP' => [
        'body' => [
            'messageId' => 'e2e-mu-' . time(),
            'type' => 'MAKEUP',
            'messageType' => 'PAID',
            'params' => [
                'orderId' => 'BT-E2E-1',
                'relationOrderId' => 'SD-E2E-TOPIC-MU-1',
                'amount' => 1.5,
                'status' => 'PAID',
            ],
        ],
        'expect_topic' => 'makeup',
        'expect_ff' => 'SD-E2E-TOPIC-MU-1',
    ],
    'PRIVATE_ORDER' => [
        'body' => [
            'messageId' => 'e2e-sy-' . time(),
            'type' => 'PRIVATE_ORDER',
            'messageType' => 'UPDATE',
            'params' => [
                'orderId' => 'SY-E2E-TOPIC-1',
                'orderNumber' => 'shop-sy-e2e',
                'status' => 'PAID',
            ],
        ],
        'expect_topic' => 'private_order',
        'expect_ff' => 'SY-E2E-TOPIC-1',
    ],
    'DISPUTES' => [
        'body' => [
            'messageId' => 'e2e-dp-' . time(),
            'type' => 'DISPUTES',
            'messageType' => 'UPDATE',
            'params' => ['orderId' => 'SD-E2E-TOPIC-DP-1', 'status' => '1'],
        ],
        'expect_topic' => 'dispute',
        'expect_ff' => 'SD-E2E-TOPIC-DP-1',
    ],
];

$ok = [];
foreach ($cases as $name => $case) {
    $raw = json_encode($case['body'], JSON_UNESCAPED_UNICODE);
    if (!is_string($raw) || $raw === '') {
        $fail('encode_' . $name);
    }
    $parsed = $provider->parseWebhook([], $raw);
    if (($parsed['ok'] ?? false) !== true) {
        $fail('parse_' . $name . ':' . json_encode($parsed));
    }
    if (($parsed['topic'] ?? '') !== $case['expect_topic']) {
        $fail('topic_' . $name . ':' . ($parsed['topic'] ?? ''));
    }
    $extEvent = (string)($parsed['external_id'] ?? ('e2e-' . $name . '-' . time()));
    $stored = json_encode([
        'event' => (string)($parsed['event'] ?? $name),
        'topic' => (string)($parsed['topic'] ?? ''),
        'external_id' => $extEvent,
        'fulfillment' => is_array($parsed['fulfillment'] ?? null) ? $parsed['fulfillment'] : [],
        'catalog' => is_array($parsed['catalog'] ?? null) ? $parsed['catalog'] : [],
        'makeup' => is_array($parsed['makeup'] ?? null) ? $parsed['makeup'] : [],
        'raw' => is_array($parsed['payload'] ?? null) ? $parsed['payload'] : [],
        'raw_body' => $raw,
        'headers' => ['User-Agent' => 'CJ Developer platform API'],
    ], JSON_UNESCAPED_UNICODE);
    $inbox->clear()->setData([
        DropshipWebhookInbox::schema_fields_ENDPOINT_CODE => 'cj.sandbox.default',
        DropshipWebhookInbox::schema_fields_PROVIDER_CODE => 'cj',
        DropshipWebhookInbox::schema_fields_EXTERNAL_EVENT_ID => $extEvent,
        DropshipWebhookInbox::schema_fields_EVENT_TYPE => (string)($parsed['event'] ?? $name),
        DropshipWebhookInbox::schema_fields_BODY => is_string($stored) ? $stored : $raw,
        DropshipWebhookInbox::schema_fields_STATUS => 'received',
        DropshipWebhookInbox::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        DropshipWebhookInbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
    ])->save();
    $id = (int)$inbox->getId();
    if ($id <= 0) {
        $fail('inbox_save_' . $name);
    }
    $processed = $svc->processOne($id, true);
    if (($processed['ok'] ?? false) !== true) {
        $fail('process_' . $name . ':' . json_encode($processed));
    }
    if ($case['expect_ff'] !== '') {
        $row = $ff->clear()
            ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, 'cj')
            ->where(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID, $case['expect_ff'])
            ->find()->fetch();
        if (!$row || !$row->getId()) {
            $fail('ff_missing_' . $name);
        }
    }
    $ok[] = $name . '#' . $id;
}

fwrite(STDOUT, 'PASS webhook-multi-topic cases=' . implode(',', $ok) . "\n");
exit(0);
