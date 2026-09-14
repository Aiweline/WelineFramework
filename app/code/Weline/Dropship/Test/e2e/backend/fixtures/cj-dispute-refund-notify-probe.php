<?php

declare(strict_types=1);

/**
 * 自造数据调试退货/退款通知：
 * 1) 真实 CJ 沙盒建单推进
 * 2) 因沙盒账号不支持 disputes/create，注入官方 DISPUTES/MAKEUP/ORDER(REFUND_COMPLETE) 信封到本机 notify
 * 退出码 0=PASS。
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

$base = 'https://p05113ef3.test.weline.com:9555';
$notify = static function (array $payload) use ($base, $fail): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($body) || $body === '') {
        $fail('encode');
    }
    $url = rtrim($base, '/') . '/dropship/frontend/callback/notify?endpoint_code=' . rawurlencode('cj.sandbox.default');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        $fail("http code={$code} err={$err} body=" . substr((string)$raw, 0, 300));
    }
    $resp = json_decode((string)$raw, true);
    if (!is_array($resp) || ($resp['ok'] ?? false) !== true) {
        $fail('notify_not_ok:' . (string)$raw);
    }

    return $resp;
};

/** @var CjProvider $provider */
$provider = ObjectManager::getInstance(CjProvider::class);
$orderUuid = 'sbx-rfnd-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
$create = $provider->createFulfillment([
    'order_uuid' => $orderUuid,
    'order_sandbox' => true,
    'from_country_code' => 'CN',
    'lines' => [[
        'external_sku' => 'CJYD3153518',
        'external_spu' => '2609110854341614300',
        'qty' => 1,
        'line_key' => 'L1',
    ]],
    'shipping' => [
        'country_code' => 'US',
        'province' => 'CA',
        'city' => 'Los Angeles',
        'street' => '1 Main St',
        'name' => 'Refund Notify Tester',
        'phone' => '12025550123',
        'zip' => '90001',
    ],
]);
if (($create['ok'] ?? false) !== true) {
    $fail('create:' . json_encode($create, JSON_UNESCAPED_UNICODE));
}
$externalOrderId = trim((string)($create['external_order_id'] ?? ''));
if ($externalOrderId === '') {
    $fail('missing_external_order_id');
}

// 探测：沙盒纠纷 API（预期不支持，仅记录）
$disputeApiNote = 'skipped';
try {
    /** @var \Weline\CjDropshipping\Service\CjApiClient $client */
    $client = ObjectManager::getInstance(\Weline\CjDropshipping\Service\CjApiClient::class);
    $products = $client->get('/disputes/disputeProducts', ['orderId' => $externalOrderId]);
    $list = is_array($products['data']['productInfoList'] ?? null) ? $products['data']['productInfoList'] : [];
    if ($list !== []) {
        $line = $list[0];
        $created = $client->post('/disputes/create', [
            'businessDisputeId' => 'biz-' . $orderUuid,
            'orderId' => $externalOrderId,
            'disputeReasonId' => 10,
            'expectType' => 1,
            'refundType' => 1,
            'messageText' => 'probe auto dispute',
            'productInfoList' => [[
                'lineItemId' => (string)($line['lineItemId'] ?? ''),
                'quantity' => (int)($line['quantity'] ?? 1),
                'price' => (float)($line['price'] ?? 0),
            ]],
        ]);
        $disputeApiNote = (string)($created['message'] ?? json_encode($created));
    } else {
        $disputeApiNote = 'no_dispute_products';
    }
} catch (\Throwable $e) {
    $disputeApiNote = $e->getMessage();
}

$suffix = bin2hex(random_bytes(3));
$envelopes = [
    [
        'label' => 'DISPUTES_OPEN',
        'payload' => [
            'messageId' => 'dsp-open-' . $suffix,
            'type' => 'DISPUTES',
            'messageType' => 'UPDATE',
            'params' => [
                'disputeId' => 'D' . $suffix,
                'orderId' => $externalOrderId,
                'orderNumber' => $orderUuid,
                'status' => '1',
                'disputeType' => '1',
                'refundAmount' => '0',
                'totalAmount' => '8.72',
            ],
        ],
        'expect_status' => 'dispute_1',
    ],
    [
        'label' => 'DISPUTES_REFUND_AGREE',
        'payload' => [
            'messageId' => 'dsp-ok-' . $suffix,
            'type' => 'DISPUTES',
            'messageType' => 'UPDATE',
            'params' => [
                'disputeId' => 'D' . $suffix,
                'orderId' => $externalOrderId,
                'orderNumber' => $orderUuid,
                'status' => '4',
                'disputeType' => '1',
                'refundAmount' => '8.72',
                'totalAmount' => '8.72',
            ],
        ],
        'expect_status' => 'dispute_4',
    ],
    [
        'label' => 'MAKEUP_PAID',
        'payload' => [
            'messageId' => 'mu-' . $suffix,
            'type' => 'MAKEUP',
            'messageType' => 'PAID',
            'params' => [
                'orderId' => 'BT' . $suffix,
                'relationOrderId' => $externalOrderId,
                'amount' => 1.23,
                'status' => 'PAID',
                'reason' => 'Postage difference',
            ],
        ],
        'expect_status' => 'makeup_paid',
    ],
    [
        'label' => 'ORDER_REFUND_COMPLETE',
        'payload' => [
            'messageId' => 'ord-rfnd-' . $suffix,
            'type' => 'ORDER',
            'messageType' => 'UPDATE',
            'params' => [
                'cjOrderId' => $externalOrderId,
                'orderNumber' => $orderUuid,
                'orderStatus' => 'REFUND_COMPLETE',
                'logisticName' => 'CJPacket Ordinary',
                'trackNumber' => (string)($create['tracking_number'] ?? ''),
            ],
        ],
        'expect_status' => 'REFUND_COMPLETE',
    ],
];

/** @var DropshipWebhookInboxService $svc */
$svc = ObjectManager::getInstance(DropshipWebhookInboxService::class);
/** @var DropshipWebhookInbox $inbox */
$inbox = ObjectManager::getInstance(DropshipWebhookInbox::class);

$inboxIds = [];
foreach ($envelopes as $row) {
    $parsed = $provider->parseWebhook([], (string)json_encode($row['payload'], JSON_UNESCAPED_UNICODE));
    if (($parsed['ok'] ?? false) !== true) {
        $fail('parse_' . $row['label']);
    }
    $got = (string)($parsed['fulfillment']['status'] ?? '');
    if (strtolower($got) !== strtolower((string)$row['expect_status'])) {
        $fail('parse_status_' . $row['label'] . '=' . $got);
    }
    $resp = $notify($row['payload']);
    $id = (int)($resp['inbox_id'] ?? 0);
    if ($id <= 0 && empty($resp['deduped'])) {
        $fail('inbox_' . $row['label']);
    }
    if ($id <= 0) {
        $hit = $inbox->clear()
            ->where(DropshipWebhookInbox::schema_fields_EXTERNAL_EVENT_ID, (string)$row['payload']['messageId'])
            ->find()->fetch();
        $id = (int)($hit?->getId() ?? 0);
    }
    if ($id <= 0) {
        $fail('resolve_inbox_' . $row['label']);
    }
    $inboxIds[] = $id;
    $proc = $svc->processOne($id);
    if (($proc['ok'] ?? false) !== true) {
        $fail('process_' . $row['label'] . ':' . json_encode($proc));
    }
}

/** @var DropshipFulfillment $ff */
$ff = ObjectManager::getInstance(DropshipFulfillment::class);
$f = $ff->clear()
    ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, 'cj')
    ->where(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID, $externalOrderId)
    ->find()->fetch();
if (!$f || !$f->getId()) {
    $fail('fulfillment_missing');
}
$final = (string)$f->getData(DropshipFulfillment::schema_fields_STATUS);
if ($final !== 'REFUND_COMPLETE') {
    $fail('final_status=' . $final);
}

fwrite(STDOUT, 'PASS cj-dispute-refund-notify'
    . ' external=' . $externalOrderId
    . ' order_uuid=' . $orderUuid
    . ' final=' . $final
    . ' inboxes=' . implode(',', $inboxIds)
    . ' dispute_api=' . str_replace(["\n", "\r"], ' ', $disputeApiNote)
    . "\n");
exit(0);
