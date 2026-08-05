<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Model\Event\Outbox;

final class AsyncEventGarbageCollector
{
    public function __construct(
        private readonly Delivery $deliveryModel,
        private readonly Outbox $outboxModel,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    /** @return array{deliveries:int,outboxes:int} */
    public function collect(int $limit = 500): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException(__('GC limit 必须是 1 到 500 的整数'));
        }
        return $this->transactions->run(
            $this->deliveryModel->getConnection(),
            function () use ($limit): array {
                $deletedDeliveries = 0;
                $deletedOutboxes = 0;
                $shortCutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
                $deadCutoff = gmdate('Y-m-d H:i:s', time() - 180 * 86400);

                // Release already orphaned, fully expanded Outbox rows first.
                // Outbox rows with any Delivery (including replay audit rows)
                // remain authoritative and are never removed here.
                $outboxRows = $this->newOutbox()
                    ->where(Outbox::schema_fields_STATUS, 'expanded')
                    ->where(Outbox::schema_fields_EXPANDED_AT, $shortCutoff, '<=')
                    ->order(Outbox::schema_fields_ID, 'ASC')
                    ->limit($limit)
                    ->select(Outbox::schema_fields_ID)
                    ->fetchArray();
                foreach ((array)$outboxRows as $row) {
                    $outboxId = (int)($row[Outbox::schema_fields_ID] ?? 0);
                    if ($outboxId < 1 || $this->hasDeliveryForOutbox($outboxId)) {
                        continue;
                    }
                    $delete = $this->newOutbox();
                    $delete->where(Outbox::schema_fields_ID, $outboxId)
                        ->where(Outbox::schema_fields_STATUS, 'expanded');
                    $result = $delete->getQuery()
                        ->delete()
                        ->fetch();
                    if ($result === true || (is_int($result) && $result === 1)) {
                        ++$deletedOutboxes;
                    }
                }

                $remaining = $limit - $deletedOutboxes;
                $rows = $remaining > 0 ? $this->newDelivery()
                    ->where(Delivery::schema_fields_STATUS, ['succeeded', 'superseded', 'skipped'], 'IN')
                    ->where(Delivery::schema_fields_REPLAY_OF_DELIVERY_ID, null, 'IS NULL')
                    ->where(Delivery::schema_fields_FINISHED_AT, $shortCutoff, '<=')
                    ->order(Delivery::schema_fields_ID, 'ASC')
                    ->limit($remaining)
                    ->select(Delivery::schema_fields_ID . ',' . Delivery::schema_fields_STATUS)
                    ->fetchArray() : [];
                foreach ((array)$rows as $row) {
                    $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
                    if ($id > 0 && !$this->isReplayReferenced($id) && $this->deleteDelivery($id)) {
                        ++$deletedDeliveries;
                    }
                }

                $processedLongRetention = [];
                $remaining = $limit - $deletedOutboxes - $deletedDeliveries;
                if ($remaining > 0) {
                    $deadRows = $this->newDelivery()
                        ->where(Delivery::schema_fields_STATUS, 'dead')
                        ->where(Delivery::schema_fields_FINISHED_AT, $deadCutoff, '<=')
                        ->order(Delivery::schema_fields_ID, 'ASC')
                        ->limit($remaining)
                        ->select(Delivery::schema_fields_ID)
                        ->fetchArray();
                    foreach ((array)$deadRows as $row) {
                        $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
                        if ($id > 0 && !$this->isReplayReferenced($id) && $this->deleteDelivery($id)) {
                            ++$deletedDeliveries;
                            $processedLongRetention[$id] = true;
                        }
                    }
                }

                $remaining = $limit - $deletedOutboxes - $deletedDeliveries;
                if ($remaining > 0) {
                    $replayRows = $this->newDelivery()
                        ->where(Delivery::schema_fields_REPLAY_OF_DELIVERY_ID, null, 'IS NOT NULL')
                        ->where(Delivery::schema_fields_STATUS, DeliveryStateMachine::TERMINAL, 'IN')
                        ->where(Delivery::schema_fields_FINISHED_AT, $deadCutoff, '<=')
                        ->order(Delivery::schema_fields_ID, 'ASC')
                        ->limit($remaining)
                        ->select(Delivery::schema_fields_ID)
                        ->fetchArray();
                    foreach ((array)$replayRows as $row) {
                        $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
                        if ($id < 1 || isset($processedLongRetention[$id]) || $this->isReplayReferenced($id)) {
                            continue;
                        }
                        if ($this->deleteDelivery($id)) {
                            ++$deletedDeliveries;
                        }
                    }
                }
                return ['deliveries' => $deletedDeliveries, 'outboxes' => $deletedOutboxes];
            },
        );
    }

    private function deleteDelivery(int $deliveryId): bool
    {
        $delivery = $this->newDelivery();
        $delivery->where(Delivery::schema_fields_ID, $deliveryId);
        if ($this->supportsForUpdate()) {
            $delivery->additional('FOR UPDATE');
        }
        $delivery->find()->fetch();
        if (!$delivery->getId()
            || !in_array((string)$delivery->getData(Delivery::schema_fields_STATUS), DeliveryStateMachine::TERMINAL, true)
            || $this->isReplayReferenced($deliveryId)) {
            return false;
        }
        $delete = $this->newDelivery();
        $delete->where(Delivery::schema_fields_ID, $deliveryId)
            ->where(Delivery::schema_fields_STATUS, (string)$delivery->getData(Delivery::schema_fields_STATUS));
        $result = $delete->getQuery()
            ->delete()
            ->fetch();
        return $result === true || (is_int($result) && $result === 1);
    }

    private function isReplayReferenced(int $deliveryId): bool
    {
        if ($this->newDelivery()
            ->where(Delivery::schema_fields_REPLAY_OF_DELIVERY_ID, $deliveryId)
            ->total() > 0) {
            return true;
        }

        // Replay creates its Outbox in the same transaction that locks the
        // source Delivery; the child Delivery appears only after relay. Keep
        // the source while that pending/relaying audit edge exists.
        foreach (['pending', 'relaying'] as $status) {
            if ($this->newOutbox()
                ->where(Outbox::schema_fields_STATUS, $status)
                ->where(
                    Outbox::schema_fields_OBSERVER_TARGETS_JSON,
                    '%"replay_of_delivery_id":' . $deliveryId . ',%',
                    'LIKE',
                )
                ->total() > 0) {
                return true;
            }
        }

        return false;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->deliveryModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function hasDeliveryForOutbox(int $outboxId): bool
    {
        return $this->newDelivery()
            ->where(Delivery::schema_fields_OUTBOX_ID, $outboxId)
            ->total() > 0;
    }

    private function newDelivery(): Delivery
    {
        $model = clone $this->deliveryModel;
        return $model->clearData()->clearQuery();
    }

    private function newOutbox(): Outbox
    {
        $model = clone $this->outboxModel;
        return $model->clearData()->clearQuery();
    }
}
