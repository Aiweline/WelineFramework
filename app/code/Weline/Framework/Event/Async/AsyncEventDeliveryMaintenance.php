<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Api\Event\AsyncEventDeliveryMaintenanceInterface;
use Weline\Framework\Api\Event\AsyncEventTransportInterface;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;

final class AsyncEventDeliveryMaintenance implements AsyncEventDeliveryMaintenanceInterface
{
    private const PROVISIONING_LEASE_SECONDS = 60;

    public function __construct(
        private readonly Delivery $deliveryModel,
        private readonly DeliveryStateMachine $deliveries,
        private readonly OutboxRelay $outboxRelay,
        private readonly AsyncEventGarbageCollector $garbageCollector,
        private readonly RuntimeProviderResolver $providerResolver,
        private readonly AsyncEventDiagnostics $diagnostics,
    ) {
    }

    public function relayOutbox(int $limit = 50): array
    {
        return $this->outboxRelay->relayAvailable($this->limit($limit));
    }

    public function reconcileTransport(int $limit = 50): array
    {
        $limit = $this->limit($limit);
        $expiredProvisioning = $this->dueIds(
            'provisioning',
            Delivery::schema_fields_LEASE_UNTIL,
            $limit,
            true,
        );
        $queued = $this->statusIds('queued', $limit);
        $work = $this->interleaveBuckets([
            array_map(static fn(int $id): array => ['kind' => 'provision', 'id' => $id], array_values($expiredProvisioning)),
            array_map(static fn(int $id): array => ['kind' => 'dispatch', 'id' => $id], array_values($queued)),
        ], $limit);
        $result = $this->provisionResult();
        if ($work === []) {
            return $result;
        }
        $transport = $this->transport();
        if (!$transport instanceof AsyncEventTransportInterface) {
            $result['noop'] = count($work);
            return $result;
        }

        foreach ($work as $item) {
            ++$result['processed'];
            $status = $item['kind'] === 'provision'
                ? $this->provisionOne($item['id'], $transport)
                : $this->dispatchOne($item['id'], $transport);
            ++$result[$status];
        }

        return $result;
    }

    public function provisionDue(int $limit = 50): array
    {
        $limit = $this->limit($limit);
        $pending = $this->dueIds('pending', Delivery::schema_fields_PROVISION_AVAILABLE_AT, $limit, true);
        $retry = $this->dueIds('retry_wait', Delivery::schema_fields_NEXT_RETRY_AT, $limit, false);
        $ids = $this->interleaveBuckets([array_values($pending), array_values($retry)], $limit);
        $result = $this->provisionResult();
        if ($ids === []) {
            return $result;
        }
        $transport = $this->transport();
        if (!$transport instanceof AsyncEventTransportInterface) {
            $result['noop'] = count($ids);
            return $result;
        }

        foreach ($ids as $deliveryId) {
            ++$result['processed'];
            $status = $this->provisionOne($deliveryId, $transport);
            ++$result[$status];
        }

        return $result;
    }

    public function terminateTimedOut(int $limit = 50): array
    {
        $rows = $this->timedOutRows($this->limit($limit));
        $result = [
            'processed' => 0,
            'terminated' => 0,
            'retry_wait' => 0,
            'dead' => 0,
            'unconfirmed' => 0,
            'noop' => 0,
        ];
        if ($rows === []) {
            return $result;
        }
        $transport = $this->transport();
        if (!$transport instanceof AsyncEventTransportInterface) {
            $result['noop'] = count($rows);
            return $result;
        }

        foreach ($rows as $row) {
            ++$result['processed'];
            $deliveryId = (int)$row[Delivery::schema_fields_ID];
            $attemptNo = (int)$row[Delivery::schema_fields_ATTEMPT_NO];
            $handle = (string)$row[Delivery::schema_fields_TRANSPORT_HANDLE];
            $fenceToken = (string)$row[Delivery::schema_fields_LEASE_TOKEN];
            try {
                $termination = $this->validateTerminationResult($transport->terminate($handle, $fenceToken));
                if ($termination['confirmed']) {
                    ++$result['terminated'];
                    $status = $this->deliveries->timeoutTerminated(
                        $deliveryId,
                        $attemptNo,
                        $handle,
                        $fenceToken,
                    );
                    ++$result[$status];
                    continue;
                }
                $errorCode = $termination['error_code'] !== ''
                    ? $termination['error_code']
                    : 'transport_termination_unconfirmed';
                $status = $this->deliveries->terminationUnconfirmed(
                    $deliveryId,
                    $attemptNo,
                    $handle,
                    $fenceToken,
                    $errorCode,
                    __('Transport 未确认终止 Worker'),
                );
            } catch (\Throwable $throwable) {
                $errorCode = $this->transportFailureCode('termination', $throwable);
                $this->diagnostics->providerUnavailable(
                    'async_delivery_termination',
                    $errorCode,
                    $throwable->getMessage(),
                );
                $status = $this->deliveries->terminationUnconfirmed(
                    $deliveryId,
                    $attemptNo,
                    $handle,
                    $fenceToken,
                    $errorCode,
                    $throwable->getMessage(),
                );
            }
            if ($status === 'running') {
                ++$result['unconfirmed'];
            } else {
                ++$result[$status];
            }
        }

        return $result;
    }

    public function collectGarbage(int $limit = 50): array
    {
        return $this->garbageCollector->collect($this->limit($limit));
    }

    /** @return 'provisioned'|'dispatched'|'failed'|'noop' */
    private function provisionOne(int $deliveryId, AsyncEventTransportInterface $transport): string
    {
        $claim = $this->deliveries->claimProvisioning(
            $deliveryId,
            'async-maintenance:' . getmypid(),
            self::PROVISIONING_LEASE_SECONDS,
        );
        if ($claim === null) {
            return 'noop';
        }
        $candidateAttempt = $claim['candidate_attempt'];
        $leaseToken = $claim['lease_token'];
        $idempotencyKey = 'delivery:' . $deliveryId . ':attempt:' . $candidateAttempt;
        try {
            $provisioned = $this->validateProvisionResult($transport->provision(
                $deliveryId,
                $candidateAttempt,
                $idempotencyKey,
                ['delivery_id' => $deliveryId, 'attempt_no' => $candidateAttempt],
            ));
        } catch (\Throwable $throwable) {
            $errorCode = $this->transportFailureCode('provision', $throwable);
            $this->diagnostics->providerUnavailable(
                'async_delivery_provision',
                $errorCode,
                $throwable->getMessage(),
            );
            $this->deliveries->provisionFailed(
                $deliveryId,
                $leaseToken,
                $errorCode,
                $throwable->getMessage(),
            );
            return 'failed';
        }

        $queueId = isset($provisioned['metadata']['queue_id'])
            && is_int($provisioned['metadata']['queue_id'])
            && $provisioned['metadata']['queue_id'] > 0
            ? $provisioned['metadata']['queue_id']
            : null;
        try {
            $transportName = $transport->name();
        } catch (\Throwable $throwable) {
            $this->diagnostics->providerUnavailable(
                'async_delivery_transport_name',
                'transport_name_failed',
                $throwable->getMessage(),
            );
            $this->deliveries->provisionFailed(
                $deliveryId,
                $leaseToken,
                'transport_name_failed',
                $throwable->getMessage(),
            );
            return 'failed';
        }
        if (!$this->deliveries->bindTransport(
            $deliveryId,
            $leaseToken,
            $candidateAttempt,
            $transportName,
            $provisioned['handle'],
            $idempotencyKey,
            $queueId,
        )) {
            return 'noop';
        }

        try {
            $dispatch = $this->validateDispatchResult($transport->dispatch($provisioned['handle']));
        } catch (\Throwable $throwable) {
            $errorCode = $this->transportFailureCode('dispatch', $throwable);
            $this->diagnostics->providerUnavailable(
                'async_delivery_dispatch',
                $errorCode,
                $throwable->getMessage(),
            );
            w_log_error(
                'event_async_relay_retry',
                ['delivery_id' => $deliveryId, 'error_code' => $errorCode],
                'event_async.log',
            );
            return 'provisioned';
        }

        return $dispatch['accepted'] ? 'dispatched' : 'provisioned';
    }

    /** @return 'provisioned'|'dispatched'|'failed'|'noop' */
    private function dispatchOne(int $deliveryId, AsyncEventTransportInterface $transport): string
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null || (string)$delivery->getData(Delivery::schema_fields_STATUS) !== 'queued') {
            return 'noop';
        }
        $handle = (string)$delivery->getData(Delivery::schema_fields_TRANSPORT_HANDLE);
        if ($handle === '') {
            return 'failed';
        }
        try {
            $dispatch = $this->validateDispatchResult($transport->dispatch($handle));
        } catch (\Throwable $throwable) {
            $errorCode = $this->transportFailureCode('dispatch', $throwable);
            $this->diagnostics->providerUnavailable(
                'async_delivery_redispatch',
                $errorCode,
                $throwable->getMessage(),
            );
            w_log_error(
                'event_async_relay_retry',
                ['delivery_id' => $deliveryId, 'error_code' => $errorCode],
                'event_async.log',
            );
            return 'failed';
        }

        return $dispatch['accepted'] ? 'dispatched' : 'provisioned';
    }

    private function transport(): ?AsyncEventTransportInterface
    {
        $resolution = $this->providerResolver->resolveDetailed(AsyncEventTransportInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            $this->diagnostics->providerNotConfigured('async_delivery_maintenance');
            return null;
        }
        if (!$resolution->isAvailable() || !$resolution->provider instanceof AsyncEventTransportInterface) {
            $this->diagnostics->providerUnavailable(
                'async_delivery_maintenance',
                $resolution->errorCode !== '' ? $resolution->errorCode : 'transport_unavailable',
                $resolution->error,
            );
            return null;
        }

        try {
            $name = $resolution->provider->name();
        } catch (\Throwable $throwable) {
            $this->diagnostics->providerUnavailable(
                'async_delivery_maintenance',
                'transport_name_failed',
                $throwable->getMessage(),
            );
            return null;
        }
        if ($name === '' || strlen($name) > 64) {
            $this->diagnostics->providerUnavailable(
                'async_delivery_maintenance',
                'transport_name_invalid',
                __('Transport 名称必须是 1–64 字节'),
            );
            return null;
        }

        $this->diagnostics->providerAvailable('async_delivery_maintenance');
        return $resolution->provider;
    }

    /** @return array{handle:string,metadata:array<string,mixed>,created:bool} */
    private function validateProvisionResult(array $result): array
    {
        if (!isset($result['handle']) || !is_string($result['handle']) || $result['handle'] === '') {
            throw new \UnexpectedValueException('transport_provision_handle_invalid');
        }
        if (!array_key_exists('metadata', $result) || !is_array($result['metadata'])) {
            throw new \UnexpectedValueException('transport_provision_metadata_invalid');
        }
        if (!array_key_exists('created', $result) || !is_bool($result['created'])) {
            throw new \UnexpectedValueException('transport_provision_created_invalid');
        }

        return $result;
    }

    /** @return array{accepted:bool,operation_id:string,error_code:string} */
    private function validateDispatchResult(array $result): array
    {
        if (!array_key_exists('accepted', $result) || !is_bool($result['accepted'])) {
            throw new \UnexpectedValueException('transport_dispatch_accepted_invalid');
        }
        if (!array_key_exists('operation_id', $result) || !is_string($result['operation_id'])) {
            throw new \UnexpectedValueException('transport_dispatch_operation_invalid');
        }
        if (!array_key_exists('error_code', $result) || !is_string($result['error_code'])) {
            throw new \UnexpectedValueException('transport_dispatch_error_invalid');
        }

        return $result;
    }

    /** @return array{confirmed:bool,retryable:bool,error_code:string} */
    private function validateTerminationResult(array $result): array
    {
        if (!array_key_exists('confirmed', $result) || !is_bool($result['confirmed'])) {
            throw new \UnexpectedValueException('transport_termination_confirmed_invalid');
        }
        if (!array_key_exists('retryable', $result) || !is_bool($result['retryable'])) {
            throw new \UnexpectedValueException('transport_termination_retryable_invalid');
        }
        if (!array_key_exists('error_code', $result) || !is_string($result['error_code'])) {
            throw new \UnexpectedValueException('transport_termination_error_invalid');
        }

        return $result;
    }

    /** @return array<int,int> */
    private function statusIds(string $status, int $limit): array
    {
        $rows = $this->newDelivery()
            ->where(Delivery::schema_fields_STATUS, $status)
            ->order(Delivery::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select(Delivery::schema_fields_ID)
            ->fetchArray();
        $ids = [];
        foreach ((array)$rows as $row) {
            $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return $ids;
    }

    /** @return array<int,int> */
    private function dueIds(string $status, string $timeField, int $limit, bool $includeNull): array
    {
        $buckets = [];
        $values = $includeNull ? [null, gmdate('Y-m-d H:i:s')] : [gmdate('Y-m-d H:i:s')];
        foreach ($values as $value) {
            $ids = [];
            $query = $this->newDelivery()
                ->where(Delivery::schema_fields_STATUS, $status)
                ->where($timeField, $value, $value === null ? 'IS NULL' : '<=')
                ->order(Delivery::schema_fields_ID, 'ASC')
                ->limit($limit)
                ->select(Delivery::schema_fields_ID);
            foreach ((array)$query->fetchArray() as $row) {
                $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $buckets[] = array_values($ids);
        }

        $result = [];
        foreach ($this->interleaveBuckets($buckets, $limit) as $id) {
            $result[$id] = $id;
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function timedOutRows(int $limit): array
    {
        $buckets = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ([null, $now] as $terminationNextAt) {
            $rows = [];
            $query = $this->newDelivery()
                ->where(Delivery::schema_fields_STATUS, 'running')
                ->where(Delivery::schema_fields_LEASE_UNTIL, $now, '<=')
                ->where(
                    Delivery::schema_fields_TERMINATION_NEXT_AT,
                    $terminationNextAt,
                    $terminationNextAt === null ? 'IS NULL' : '<=',
                )
                ->order(Delivery::schema_fields_ID, 'ASC')
                ->limit($limit)
                ->select(implode(',', [
                    Delivery::schema_fields_ID,
                    Delivery::schema_fields_ATTEMPT_NO,
                    Delivery::schema_fields_TRANSPORT_HANDLE,
                    Delivery::schema_fields_LEASE_TOKEN,
                ]));
            foreach ((array)$query->fetchArray() as $row) {
                $id = (int)($row[Delivery::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $rows[$id] = $row;
                }
            }
            $buckets[] = array_values($rows);
        }

        $rowsById = [];
        foreach ($this->interleaveBuckets($buckets, $limit) as $row) {
            $rowsById[(int)$row[Delivery::schema_fields_ID]] = $row;
        }

        return $rowsById;
    }

    /** @param list<list<mixed>> $buckets @return list<mixed> */
    private function interleaveBuckets(array $buckets, int $limit): array
    {
        $result = [];
        for ($offset = 0; count($result) < $limit; $offset++) {
            $progress = false;
            foreach ($buckets as $bucket) {
                if (isset($bucket[$offset])) {
                    $result[] = $bucket[$offset];
                    $progress = true;
                    if (count($result) >= $limit) {
                        break 2;
                    }
                }
            }
            if (!$progress) {
                break;
            }
        }

        return $result;
    }

    private function newDelivery(): Delivery
    {
        $model = clone $this->deliveryModel;
        return $model->clearData()->clearQuery();
    }

    /** @return array{processed:int,provisioned:int,dispatched:int,failed:int,noop:int} */
    private function provisionResult(): array
    {
        return ['processed' => 0, 'provisioned' => 0, 'dispatched' => 0, 'failed' => 0, 'noop' => 0];
    }

    private function limit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    private function transportFailureCode(string $operation, \Throwable $throwable): string
    {
        return $throwable instanceof \UnexpectedValueException
            ? 'transport_protocol_invalid'
            : 'transport_' . $operation . '_failed';
    }
}
