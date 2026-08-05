<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Api\Event\AsyncEventDeliveryRunnerInterface;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Model\Event\Delivery;

final class AsyncEventDeliveryRunner implements AsyncEventDeliveryRunnerInterface
{
    public function __construct(
        private readonly DeliveryStateMachine $deliveries,
        private readonly AsyncPayloadMapperResolver $mapperResolver,
        private readonly ObserverInvoker $observerInvoker,
        private readonly ContextSnapshot $contextSnapshot,
        private readonly CanonicalJson $canonicalJson,
        private readonly DeliveryCoalescer $coalescer,
    ) {
    }

    public function run(
        int $deliveryId,
        int $attemptNo,
        string $transportHandle,
        string $fenceToken,
    ): string {
        $delivery = $this->deliveries->claimExecution(
            $deliveryId,
            $attemptNo,
            $transportHandle,
            $fenceToken,
        );
        if ($delivery === null) {
            return 'noop';
        }

        try {
            $staleReason = $this->coalescer->staleReason($delivery);
            if ($staleReason !== null) {
                $this->deliveries->skipRunning(
                    $deliveryId,
                    $attemptNo,
                    $transportHandle,
                    $fenceToken,
                    $staleReason,
                );
                return 'noop';
            }
            $payload = $this->decode((string)$delivery->getData(Delivery::schema_fields_PAYLOAD_JSON), 'payload');
            $context = $this->decode((string)$delivery->getData(Delivery::schema_fields_CONTEXT_JSON), 'context');
            $payloadJson = $this->canonicalJson->encode($payload);
            if (!hash_equals(
                (string)$delivery->getData(Delivery::schema_fields_PAYLOAD_SHA256),
                hash('sha256', $payloadJson),
            )) {
                throw new NonRetryableAsyncEventException('payload_hash_mismatch', __('Delivery 载荷指纹校验失败'));
            }
            $eventName = (string)($payload['event_name'] ?? '');
            $schemaVersion = (int)$delivery->getData(Delivery::schema_fields_PAYLOAD_SCHEMA_VERSION);
            $observer = $this->observerInvoker->resolve(
                $eventName,
                (string)$delivery->getData(Delivery::schema_fields_OBSERVER_KEY),
                $schemaVersion,
            );
            $mapper = $this->mapperResolver->resolve(
                (string)($observer['event_async_mapper'] ?? ''),
                $eventName,
                $schemaVersion,
            );
            $eventData = $mapper->fromPayload($payload);
            $snapshotHash = (string)$delivery->getData(Delivery::schema_fields_OBSERVER_INSTANCE_HASH);
            $currentHash = (string)($observer['instance_hash'] ?? '');
            if ($snapshotHash !== '' && $currentHash !== '' && !hash_equals($snapshotHash, $currentHash)) {
                w_log_warning(
                    'event_async_delivery_state_changed',
                    [
                        'delivery_id' => $deliveryId,
                        'observer_key' => (string)$delivery->getData(Delivery::schema_fields_OBSERVER_KEY),
                        'error_code' => 'handler_changed',
                    ],
                    'event_async.log',
                );
            }
            $this->contextSnapshot->runWith(
                $context,
                fn(): mixed => $this->observerInvoker->invoke($eventName, $eventData, $observer),
            );
            if (!$this->deliveries->succeeded($deliveryId, $attemptNo, $transportHandle, $fenceToken)) {
                return 'noop';
            }
            $completed = $this->deliveries->find($deliveryId);
            if ($completed !== null) {
                $this->coalescer->markSucceeded($completed);
            }
            return 'succeeded';
        } catch (NonRetryableAsyncEventException $exception) {
            return $this->deliveries->failed(
                $deliveryId,
                $attemptNo,
                $transportHandle,
                $fenceToken,
                $exception->reasonCode,
                $exception->getMessage(),
                false,
            );
        } catch (AsyncEventValidationException|\JsonException $exception) {
            return $this->deliveries->failed(
                $deliveryId,
                $attemptNo,
                $transportHandle,
                $fenceToken,
                'payload_invalid',
                $exception->getMessage(),
                false,
            );
        } catch (\Throwable $exception) {
            return $this->deliveries->failed(
                $deliveryId,
                $attemptNo,
                $transportHandle,
                $fenceToken,
                'observer_failed',
                $exception->getMessage(),
                true,
            );
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AsyncEventValidationException(__('%{1} JSON 无效', [$label]), previous: $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new AsyncEventValidationException(__('%{1} 必须是 JSON object', [$label]));
        }
        return $value;
    }
}
