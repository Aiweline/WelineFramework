<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final readonly class RuntimeSelection
{
    public const ENDPOINT_SCHEMA_VERSION = 4;

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
     * Rehydrate the immutable selection stored in an endpoint schema v4 record.
     * Missing, malformed, or removed topology values are rejected.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $allowedFields = [
            'requested_topology',
            'effective_topology',
            'topology_source',
            'os_family',
            'event_loop_driver',
            'ssl_engine',
            'listener_mode',
            'policy_compatible',
            'reason_codes',
            'reason',
        ];
        $unknownFields = \array_values(\array_diff(\array_keys($data), $allowedFields));
        if ($unknownFields !== []) {
            throw new \RuntimeException(
                'runtime_selection contains unsupported fields: '
                . \implode(', ', \array_map(static fn(mixed $field): string => (string)$field, $unknownFields))
                . '.'
            );
        }

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
     * Rehydrate and validate the canonical selection from an endpoint schema v4 record.
     *
     * @param array<string, mixed> $endpoint
     */
    public static function fromEndpoint(array $endpoint): self
    {
        $selectionData = $endpoint['runtime_selection'] ?? null;
        if (!\is_array($selectionData)) {
            throw new \RuntimeException('WLS endpoint schema v4 requires runtime_selection.');
        }

        $selection = self::fromArray($selectionData);
        $selection->assertCanonicalEndpoint($endpoint);

        return $selection;
    }

    /**
     * Enforce the schema v4 canonical endpoint shape. RuntimeSelection is the
     * only topology fact; every flattened or legacy projection is rejected.
     *
     * @param array<string, mixed> $endpoint
     */
    public function assertCanonicalEndpoint(array $endpoint): void
    {
        $schemaVersion = $endpoint['schema_version'] ?? null;
        if (!\is_int($schemaVersion) || $schemaVersion !== self::ENDPOINT_SCHEMA_VERSION) {
            throw new \RuntimeException(
                'WLS endpoint schema_version must be exactly ' . self::ENDPOINT_SCHEMA_VERSION . '.'
            );
        }

        if (!\array_key_exists('runtime_selection', $endpoint)
            || !\is_array($endpoint['runtime_selection'])
        ) {
            throw new \RuntimeException('WLS endpoint schema v4 requires runtime_selection.');
        }
        if ($endpoint['runtime_selection'] !== $this->toArray()) {
            throw new \RuntimeException('WLS endpoint runtime_selection does not match the canonical selection.');
        }

        foreach ([
            'requested_topology',
            'effective_topology',
            'topology',
            'runtime_topology',
            'topology_source',
            'topology_reason',
            'topology_reason_codes',
            'reason_codes',
            'reason',
            'os_family',
            'event_loop_driver',
            'ssl_engine',
            'listener_mode',
            'direct_listener_mode',
            'listener_strategy',
            'policy_compatible',
            'dispatcher_enabled',
            'direct_reuse_port',
            'master_mode',
        ] as $field) {
            if (\array_key_exists($field, $endpoint)) {
                throw new \RuntimeException(
                    'WLS endpoint schema v4 forbids duplicate topology field "' . $field . '".'
                );
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
