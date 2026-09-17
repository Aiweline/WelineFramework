<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Integration\Order\OrderShippingFulfillmentGateway;
use Weline\Shipping\Model\ShippingLabelOrphan;

/** Process pending orphan label cancels with capped retries (#20). */
final class ShippingLabelOrphanProcessor
{
    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?ShippingLabelOrphan $orphanModel = null,
        private readonly ?OrderShippingFulfillmentGateway $fulfillmentGateway = null,
    ) {
    }

    public function processDue(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $now = date('Y-m-d H:i:s');
        try {
            $rows = $this->orphans()->reset()
                ->where(ShippingLabelOrphan::schema_fields_STATUS, ShippingLabelOrphan::STATUS_PENDING)
                ->where(ShippingLabelOrphan::schema_fields_NEXT_ATTEMPT_AT, $now, '<=')
                ->order(ShippingLabelOrphan::schema_fields_ID, 'ASC')
                ->limit($limit)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return ['processed' => 0, 'done' => 0, 'dead' => 0];
        }
        $done = 0;
        $dead = 0;
        $processed = 0;
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $processed++;
            $id = (int)($row[ShippingLabelOrphan::schema_fields_ID] ?? 0);
            $attempts = (int)($row[ShippingLabelOrphan::schema_fields_ATTEMPTS] ?? 0) + 1;
            $ok = $this->resolveGateway()->cancelLabelBestEffort(
                (string)($row[ShippingLabelOrphan::schema_fields_IDEMPOTENCY_KEY] ?? ''),
                (int)($row[ShippingLabelOrphan::schema_fields_CARRIER_ID] ?? 0),
                (string)($row[ShippingLabelOrphan::schema_fields_SERVICE_CODE] ?? ''),
                (string)($row[ShippingLabelOrphan::schema_fields_TRACKING_NUMBER] ?? ''),
                (string)($row[ShippingLabelOrphan::schema_fields_ORDER_NUMBER] ?? ''),
            );
            $model = $this->orphans()->reset()->load($id);
            if (!$model instanceof ShippingLabelOrphan || !(int)$model->getId()) {
                continue;
            }
            $model->setData(ShippingLabelOrphan::schema_fields_ATTEMPTS, $attempts);
            $model->setData(ShippingLabelOrphan::schema_fields_UPDATED_AT, $now);
            if ($ok) {
                $model->setData(ShippingLabelOrphan::schema_fields_STATUS, ShippingLabelOrphan::STATUS_DONE);
                $model->save();
                $done++;
                continue;
            }
            if ($attempts >= ShippingLabelOrphan::MAX_ATTEMPTS) {
                $model->setData(ShippingLabelOrphan::schema_fields_STATUS, ShippingLabelOrphan::STATUS_DEAD);
                $model->setData(ShippingLabelOrphan::schema_fields_LAST_ERROR, 'max_attempts');
                $model->save();
                $dead++;
                continue;
            }
            $delay = min(3600, (int)(30 * (2 ** max(0, $attempts - 1))));
            $model->setData(
                ShippingLabelOrphan::schema_fields_NEXT_ATTEMPT_AT,
                date('Y-m-d H:i:s', time() + $delay),
            );
            $model->setData(ShippingLabelOrphan::schema_fields_LAST_ERROR, 'cancel_failed');
            $model->save();
        }

        return ['processed' => $processed, 'done' => $done, 'dead' => $dead];
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    private function orphans(): ShippingLabelOrphan
    {
        return $this->orphanModel ?? $this->om()->getInstance(ShippingLabelOrphan::class);
    }

    private function resolveGateway(): OrderShippingFulfillmentGateway
    {
        return $this->fulfillmentGateway ?? $this->om()->getInstance(OrderShippingFulfillmentGateway::class);
    }
}
