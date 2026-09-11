<?php

declare(strict_types=1);

namespace Weline\Dropship\Cron;

use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

class DropshipFulfillmentPull implements CronTaskInterface
{
    public function name(): string
    {
        return 'Weline_Dropship::fulfillment_pull';
    }

    public function execute_name(): string
    {
        return 'Weline\\Dropship\\Cron\\DropshipFulfillmentPull::execute';
    }

    public function tip(): string
    {
        return '拉取远程履约与物流状态';
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 45;
    }

    public function execute(): string
    {
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        /** @var DropshipFulfillment $model */
        $model = ObjectManager::getInstance(DropshipFulfillment::class);
        $rows = $model->clear()->limit(100)->select()->fetchArray();
        $n = 0;
        foreach ((array)$rows as $row) {
            $provider = $channels->getProvider((string)($row['provider_code'] ?? ''));
            if (!$provider instanceof DropshipFulfillmentProviderInterface) {
                continue;
            }
            $result = $provider->queryFulfillment([
                'order_uuid' => $row['order_uuid'] ?? '',
                'external_order_id' => $row['external_order_id'] ?? '',
            ]);
            if (!($result['ok'] ?? false)) {
                continue;
            }
            $tracking = (array)($result['tracking'] ?? []);
            $entity = $model->clear()->where(DropshipFulfillment::schema_fields_ID, (int)$row['fulfillment_id'])->find()->fetch();
            if ($entity && $entity->getId()) {
                $entity->setData([
                    DropshipFulfillment::schema_fields_STATUS => (string)($result['status'] ?? $row['status']),
                    DropshipFulfillment::schema_fields_TRACKING_NUMBER => (string)($tracking['number'] ?? ''),
                    DropshipFulfillment::schema_fields_CARRIER => (string)($tracking['carrier'] ?? ''),
                    DropshipFulfillment::schema_fields_TRACKING_JSON => json_encode($tracking, JSON_UNESCAPED_UNICODE),
                    DropshipFulfillment::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])->save();
                $n++;
            }
        }

        return 'pulled ' . $n;
    }
}
