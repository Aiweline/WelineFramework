<?php

declare(strict_types=1);

namespace Weline\Dropship\Cron;

use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

class DropshipOutboxRetry implements CronTaskInterface
{
    public function name(): string
    {
        return 'Weline_Dropship::outbox_retry';
    }

    public function execute_name(): string
    {
        return 'Weline\\Dropship\\Cron\\DropshipOutboxRetry::execute';
    }

    public function tip(): string
    {
        return '扫描 pending/error outbox 补推入队';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 30;
    }

    public function execute(): string
    {
        /** @var DropshipPushOutbox $model */
        $model = ObjectManager::getInstance(DropshipPushOutbox::class);
        $rows = $model->clear()
            ->where(DropshipPushOutbox::schema_fields_STATUS, [DropshipPushOutbox::STATUS_PENDING, DropshipPushOutbox::STATUS_ERROR], 'IN')
            ->limit(100)
            ->select()
            ->fetchArray();
        $n = 0;
        foreach ((array)$rows as $row) {
            $bizKey = (string)($row['biz_key'] ?? '');
            if ($bizKey === '' || !function_exists('w_query')) {
                continue;
            }
            w_query('queue', 'createIfAbsent', [
                'class' => \Weline\Dropship\Queue\DropshipOrderPushConsumer::class,
                'name' => 'Dropship retry ' . $bizKey,
                'module' => 'Weline_Dropship',
                'content' => ['biz_key' => $bizKey],
                'status' => 'pending',
                'auto' => true,
                'biz_key' => $bizKey . ':retry:' . date('YmdHi'),
                'idempotency_scope' => 'dropship_push_retry',
                'idempotency_key' => $bizKey . ':retry:' . date('YmdHi'),
            ]);
            $n++;
        }

        return 'retry enqueued ' . $n;
    }
}
