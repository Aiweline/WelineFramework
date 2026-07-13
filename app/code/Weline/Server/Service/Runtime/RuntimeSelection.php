<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final readonly class RuntimeSelection
{
    public const ENDPOINT_SCHEMA_VERSION = 3;

    /**
     * @param string[] $reasonCodes
     */
    public function __construct(
        public RequestedTopology $requestedTopology,
        public EffectiveTopology $effectiveTopology,
        public string $source,
        public string $osFamily,
        public string $eventLoopDriver,
        public string $sslEngine,
        public string $listenerMode,
        public bool $policyCompatible,
        public array $reasonCodes,
        public string $reason,
    ) {
    }

    public function isDirect(): bool
    {
        return $this->effectiveTopology->isDirect();
    }

    public function isDispatcher(): bool
    {
        return $this->effectiveTopology->isDispatcher();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested_topology' => $this->requestedTopology->value,
            'effective_topology' => $this->effectiveTopology->value,
            'topology_source' => $this->source,
            'os_family' => $this->osFamily,
            'event_loop_driver' => $this->eventLoopDriver,
            'ssl_engine' => $this->sslEngine,
            'listener_mode' => $this->listenerMode,
            'policy_compatible' => $this->policyCompatible,
            'reason_codes' => $this->reasonCodes,
            'reason' => $this->reason,
        ];
    }

    /**
     * Rehydrate the immutable selection stored in an endpoint schema v3 record.
     * Missing or malformed fields are rejected instead of being inferred from
     * legacy projections, because that would recreate multiple topology facts.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $requested = RequestedTopology::tryFrom(self::requiredString($data, 'requested_topology'));
        if (!$requested instanceof RequestedTopology) {
            throw new \RuntimeException('runtime_selection.requested_topology is invalid.');
        }

        $effective = EffectiveTopology::tryFrom(self::requiredString($data, 'effective_topology'));
        if (!$effective instanceof EffectiveTopology) {
            throw new \RuntimeException('runtime_selection.effective_topology is invalid.');
        }

        if (!\array_key_exists('policy_compatible', $data) || !\is_bool($data['policy_compatible'])) {
            throw new \RuntimeException('runtime_selection.policy_compatible must be a boolean.');
        }

        $reasonCodes = $data['reason_codes'] ?? null;
        if (!\is_array($reasonCodes) || !\array_is_list($reasonCodes) || $reasonCodes === []) {
            throw new \RuntimeException('runtime_selection.reason_codes must be a non-empty string list.');
        }
        foreach ($reasonCodes as $reasonCode) {
            if (!\is_string($reasonCode) || \trim($reasonCode) === '') {
                throw new \RuntimeException('runtime_selection.reason_codes must contain only non-empty strings.');
            }
        }

        $listenerMode = self::requiredString($data, 'listener_mode');
        if ($effective->isDirect() && !\in_array($listenerMode, ['reuseport', 'shared_fd'], true)) {
            throw new \RuntimeException('Direct runtime_selection.listener_mode must be reuseport or shared_fd.');
        }
        if ($effective->isDispatcher() && $listenerMode !== 'single') {
            throw new \RuntimeException('Dispatcher runtime_selection.listener_mode must be single.');
        }

        return new self(
            requestedTopology: $requested,
            effectiveTopology: $effective,
            source: self::requiredString($data, 'topology_source'),
            osFamily: self::requiredString($data, 'os_family'),
            eventLoopDriver: self::requiredString($data, 'event_loop_driver'),
            sslEngine: self::requiredString($data, 'ssl_engine'),
            listenerMode: $listenerMode,
            policyCompatible: $data['policy_compatible'],
            reasonCodes: \array_values($reasonCodes),
            reason: self::requiredString($data, 'reason'),
        );
    }

    /**
     * Ensure all schema v3 compatibility projections still describe this one
     * immutable selection. A conflict is corruption and must fail closed.
     *
     * @param array<string, mixed> $endpoint
     */
    public function assertEndpointProjection(array $endpoint): void
    {
        $expected = [
            'requested_topology' => $this->requestedTopology->value,
            'effective_topology' => $this->effectiveTopology->value,
            'topology' => $this->effectiveTopology->value,
            'topology_source' => $this->source,
            'topology_reason' => $this->reason,
            'topology_reason_codes' => $this->reasonCodes,
            'os_family' => $this->osFamily,
            'event_loop_driver' => $this->eventLoopDriver,
            'ssl_engine' => $this->sslEngine,
            'direct_listener_mode' => $this->listenerMode,
            'listener_strategy' => $this->listenerMode,
            'policy_compatible' => $this->policyCompatible,
            'dispatcher_enabled' => $this->isDispatcher(),
            'master_mode' => $this->effectiveTopology->value,
        ];

        foreach ($expected as $field => $value) {
            if (!\array_key_exists($field, $endpoint)) {
                throw new \RuntimeException('WLS endpoint schema v3 is missing projection field "' . $field . '".');
            }
            if ($endpoint[$field] !== $value) {
                throw new \RuntimeException('WLS endpoint schema v3 projection conflict at "' . $field . '".');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || \trim($value) === '') {
            throw new \RuntimeException('runtime_selection.' . $field . ' must be a non-empty string.');
        }

        return $value;
    }
}
