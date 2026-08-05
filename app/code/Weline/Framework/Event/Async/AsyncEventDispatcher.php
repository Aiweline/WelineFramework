<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Api\Event\AsyncEventTransportInterface;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Model\Event\Outbox;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;

final class AsyncEventDispatcher
{
    public function __construct(
        private readonly AsyncPayloadMapperResolver $mapperResolver,
        private readonly RuntimeProviderResolver $providerResolver,
        private readonly OutboxRepository $outboxRepository,
        private readonly ContextSnapshot $contextSnapshot,
        private readonly AsyncEventDiagnostics $diagnostics,
        private readonly AsyncEventConfig $config,
        private readonly OutboxRelayScheduler $relayScheduler,
    ) {
    }

    /** @param list<array<string,mixed>> $observerConfigs */
    public function dispatch(string $eventName, mixed $data, array $observerConfigs): AsyncEventDispatchResult
    {
        $asyncObservers = array_values(array_filter(
            $observerConfigs,
            static fn(mixed $observer): bool => is_array($observer)
                && strtolower((string)($observer['delivery'] ?? 'sync')) === 'async',
        ));
        if ($asyncObservers === []) {
            return new AsyncEventDispatchResult('skipped', reason: 'no_async_observers');
        }
        if (!$this->config->producerEnabled()) {
            return new AsyncEventDispatchResult('skipped', targetCount: count($asyncObservers), reason: 'producer_disabled');
        }

        $transport = $this->providerResolver->resolveDetailed(AsyncEventTransportInterface::class);
        if ($transport->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            $this->diagnostics->providerNotConfigured($eventName);
            return new AsyncEventDispatchResult('skipped', targetCount: count($asyncObservers), reason: 'transport_not_configured');
        }
        if ($transport->status === RuntimeProviderResolution::CONFIGURED_UNAVAILABLE) {
            $this->diagnostics->providerUnavailable($eventName, $transport->errorCode, $transport->error);
        } else {
            $this->diagnostics->providerAvailable($eventName);
        }

        $eventConfig = $this->eventConfig($asyncObservers);
        $mapper = $this->mapperResolver->resolve(
            $eventConfig['async_mapper'],
            $eventName,
            $eventConfig['schema_version'],
        );
        $payload = $mapper->toPayload($data);
        $mapper->validate($payload);
        $context = is_array($payload['context'] ?? null)
            ? $payload['context']
            : $this->contextSnapshot->capture();
        $this->contextSnapshot->validate($context);
        $targets = $this->targets($asyncObservers, $data);
        $outbox = $this->outboxRepository->append(
            $eventName,
            $eventConfig['schema_version'],
            $payload,
            $context,
            $targets,
        );
        $outboxId = (int)$outbox->getData(Outbox::schema_fields_ID);
        $this->relayScheduler->afterCommit($outboxId);

        return new AsyncEventDispatchResult(
            'persisted',
            $outboxId,
            count($targets),
            $transport->status,
        );
    }

    /**
     * @param list<array<string,mixed>> $observers
     * @return array{schema_version:int,async_mapper:string,data_contract:string}
     */
    private function eventConfig(array $observers): array
    {
        $first = $observers[0];
        $config = [
            'schema_version' => (int)($first['event_schema_version'] ?? 0),
            'async_mapper' => ltrim(trim((string)($first['event_async_mapper'] ?? '')), '\\'),
            'data_contract' => trim((string)($first['event_data_contract'] ?? '')),
        ];
        if ($config['schema_version'] < 1 || $config['async_mapper'] === '' || $config['data_contract'] === '') {
            throw new AsyncEventValidationException(__('异步事件缺少 schema_version、async_mapper 或 data_contract'));
        }
        foreach ($observers as $observer) {
            if ((int)($observer['event_schema_version'] ?? 0) !== $config['schema_version']
                || ltrim(trim((string)($observer['event_async_mapper'] ?? '')), '\\') !== $config['async_mapper']
                || trim((string)($observer['event_data_contract'] ?? '')) !== $config['data_contract']) {
                throw new AsyncEventValidationException(__('同一事件的 async Observer 必须共用一致 Mapper 规约'));
            }
        }
        return $config;
    }

    /**
     * @param list<array<string,mixed>> $observers
     * @return list<array<string,mixed>>
     */
    private function targets(array $observers, mixed $data): array
    {
        $targets = [];
        foreach ($observers as $observer) {
            $retry = (string)($observer['retry'] ?? 'standard');
            $coalesce = (string)($observer['coalesce'] ?? 'none');
            $observerKey = trim((string)($observer['observer_key'] ?? ''));
            if ($observerKey === '' || strlen($observerKey) > 191) {
                throw new AsyncEventValidationException(__('异步 Observer 缺少合法 observer_key'));
            }
            $targets[] = [
                'observer_key' => $observerKey,
                'module' => (string)($observer['module'] ?? ''),
                'name' => (string)($observer['name'] ?? ''),
                'instance_hash' => (string)($observer['instance_hash'] ?? ''),
                'retry' => $retry,
                'coalesce' => $coalesce,
                'timeout' => (int)($observer['timeout'] ?? 30),
                'max_attempts' => $retry === 'none' ? 1 : 6,
                'coalesce_key' => $coalesce === 'latest' && $data instanceof ResourceChange
                    ? $data->coalesceKey()
                    : '',
            ];
        }
        return $targets;
    }
}
