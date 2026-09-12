<?php

declare(strict_types=1);

/**
 * 章节通路探针：CJ Order exist 幂等 + dead 回收（非 Playwright UI）。
 * 退出码 0=PASS。
 */
require dirname(__DIR__, 7) . '/bootstrap.php';

use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Dropship\Service\DropshipOutboxService;
use Weline\Framework\Manager\ObjectManager;

$fail = static function (string $m): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    exit(1);
};

if (!CjProvider::isDuplicateCreateMessage('Order exist, please do not duplicate create')) {
    $fail('isDuplicateCreateMessage');
}

$bizKey = 'dropship:cj:create:sandbox-ob3-20260911162331';
/** @var DropshipPushOutbox $model */
$model = ObjectManager::getInstance(DropshipPushOutbox::class);
$row = $model->clear()->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)->find()->fetch();
if (!$row || !$row->getId()) {
    $fail('outbox missing');
}

// 人为置 dead 再回收，验证完整通路
$row->setData([
    DropshipPushOutbox::schema_fields_STATUS => DropshipPushOutbox::STATUS_DEAD,
    DropshipPushOutbox::schema_fields_LAST_ERROR => 'Order exist, please do not duplicate create',
])->save();

/** @var DropshipOutboxService $svc */
$svc = ObjectManager::getInstance(DropshipOutboxService::class);
$result = $svc->processOne($bizKey);
if (($result['recovered'] ?? false) !== true) {
    $fail('processOne not recovered: ' . json_encode($result));
}

$row = $model->clear()->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)->find()->fetch();
$status = (string)$row->getData(DropshipPushOutbox::schema_fields_STATUS);
$payload = json_decode((string)$row->getData(DropshipPushOutbox::schema_fields_PAYLOAD_JSON), true) ?: [];
$comp = is_array($payload['compensation'] ?? null) ? $payload['compensation'] : [];
if ($status !== DropshipPushOutbox::STATUS_DONE) {
    $fail('status=' . $status);
}
if (($comp['status'] ?? '') !== 'recovered_existing') {
    $fail('compensation=' . json_encode($comp));
}

fwrite(STDOUT, "PASS cj-order-exist-recover external=" . ($comp['external_order_id'] ?? '') . "\n");
exit(0);
