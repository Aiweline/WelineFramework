<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Read-only, report-safe projection of one persisted WLS endpoint record.
 *
 * Schema v3 never falls back to legacy inference: RuntimeSelection and every
 * compatibility projection must agree before topology metadata is exposed as
 * an observed runtime fact. Older endpoint schemas remain visible as an
 * explicitly labelled legacy projection.
 */
final readonly class RuntimeEndpointMetadata
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(private array $metadata)
    {
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    public static function fromEndpoint(array $endpoint): self
    {
        $schemaVersion = (int)($endpoint['schema_version'] ?? 0);
        $selectionData = \is_array($endpoint['runtime_selection'] ?? null)
            ? $endpoint['runtime_selection']
            : [];
        $selection = null;
        $selectionValid = null;
        $selectionError = null;

        if ($schemaVersion >= RuntimeSelection::ENDPOINT_SCHEMA_VERSION) {
            try {
                if ($selectionData === []) {
                    throw new \RuntimeException('runtime_selection is missing.');
                }
                $selection = RuntimeSelection::fromArray($selectionData);
                $selection->assertEndpointProjection($endpoint);
                $selectionValid = true;
            } catch (\Throwable $exception) {
                $selectionValid = false;
                $selectionError = $exception->getMessage();
            }
        } elseif ($selectionData !== []) {
            try {
                $selection = RuntimeSelection::fromArray($selectionData);
                $selectionValid = true;
            } catch (\Throwable $exception) {
                $selectionValid = false;
                $selectionError = $exception->getMessage();
            }
        }

        $authoritativeSelection = $schemaVersion >= RuntimeSelection::ENDPOINT_SCHEMA_VERSION
            && $selectionValid === true
            && $selection instanceof RuntimeSelection;
        $legacySelection = $schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION
            && $selectionValid === true
            && $selection instanceof RuntimeSelection;

        $effectiveTopology = null;
        if ($authoritativeSelection || $legacySelection) {
            $effectiveTopology = $selection->effectiveTopology->value;
        } elseif ($schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION) {
            $effectiveTopology = self::inferLegacyTopology($endpoint);
        }

        $requestedTopology = ($authoritativeSelection || $legacySelection)
            ? $selection->requestedTopology->value
            : ($schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION
                ? self::firstNonEmptyString([$endpoint['requested_topology'] ?? null])
                : null);
        $topologySource = ($authoritativeSelection || $legacySelection)
            ? $selection->source
            : ($schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION
                ? self::firstNonEmptyString([$endpoint['topology_source'] ?? null, 'legacy_projection'])
                : null);
        $topologyReason = ($authoritativeSelection || $legacySelection)
            ? $selection->reason
            : ($schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION
                ? self::firstNonEmptyString([$endpoint['topology_reason'] ?? null])
                : null);
        $reasonCodes = ($authoritativeSelection || $legacySelection)
            ? $selection->reasonCodes
            : self::stringList($endpoint['topology_reason_codes'] ?? []);
        $osFamily = ($authoritativeSelection || $legacySelection)
            ? $selection->osFamily
            : self::firstNonEmptyString([$endpoint['os_family'] ?? null, $endpoint['os'] ?? null]);
        $eventLoopDriver = ($authoritativeSelection || $legacySelection)
            ? $selection->eventLoopDriver
            : self::firstNonEmptyString([$endpoint['event_loop_driver'] ?? null, $endpoint['event_loop'] ?? null]);
        $sslEngine = ($authoritativeSelection || $legacySelection)
            ? $selection->sslEngine
            : self::firstNonEmptyString([$endpoint['ssl_engine'] ?? null]);
        $listenerStrategy = ($authoritativeSelection || $legacySelection)
            ? $selection->listenerMode
            : ($schemaVersion < RuntimeSelection::ENDPOINT_SCHEMA_VERSION
                ? self::firstNonEmptyString([
                    $endpoint['listener_strategy'] ?? null,
                    $endpoint['direct_listener_mode'] ?? null,
                ])
                : null);
        $policyCompatible = ($authoritativeSelection || $legacySelection)
            ? $selection->policyCompatible
            : (\is_bool($endpoint['policy_compatible'] ?? null) ? $endpoint['policy_compatible'] : null);
        $httpProtocolSelection = \is_array($endpoint['http_protocol_selection'] ?? null)
            ? $endpoint['http_protocol_selection']
            : [];
        $httpProtocols = self::stringList(
            $endpoint['http_protocols'] ?? ($httpProtocolSelection['protocols'] ?? [])
        );
        $httpPreferredProtocol = self::firstNonEmptyString([
            $endpoint['http_preferred_protocol'] ?? null,
            $httpProtocolSelection['preferred'] ?? null,
        ]);
        $protocolEdgeEnabled = \array_key_exists('protocol_edge_enabled', $endpoint)
            ? (bool)$endpoint['protocol_edge_enabled']
            : null;
        $tlsSessionResumption = \array_key_exists('tls_session_resumption', $endpoint)
            ? (bool)$endpoint['tls_session_resumption']
            : (\array_key_exists('tls_session_resumption', $httpProtocolSelection)
                ? (bool)$httpProtocolSelection['tls_session_resumption']
                : null);

        $host = \strtolower(\trim((string)($endpoint['host'] ?? '')));
        $localRuntime = \in_array($host, ['', '127.0.0.1', 'localhost', '::1', '0.0.0.0', '::'], true);
        $eventExtensionVersion = self::firstNonEmptyString([
            $endpoint['event_extension_version'] ?? null,
            $localRuntime && \extension_loaded('event') ? (\phpversion('event') ?: null) : null,
        ]);

        $metadata = [
            'metadata_source' => $schemaVersion >= RuntimeSelection::ENDPOINT_SCHEMA_VERSION
                ? 'endpoint_schema_v3'
                : 'endpoint_legacy_projection',
            'endpoint_schema_version' => $schemaVersion,
            'runtime_selection_valid' => $selectionValid,
            'runtime_selection_error' => $selectionError,
            'runtime_selection' => ($authoritativeSelection || $legacySelection) ? $selection->toArray() : null,
            'requested_topology' => $requestedTopology,
            'effective_topology' => $effectiveTopology,
            'topology' => $effectiveTopology,
            'topology_source' => $topologySource,
            'topology_reason' => $topologyReason,
            'topology_reason_codes' => $reasonCodes,
            'listener_strategy' => $listenerStrategy,
            'os_family' => $osFamily,
            'os' => $osFamily,
            'architecture' => self::firstNonEmptyString([
                $endpoint['architecture'] ?? null,
                $endpoint['arch'] ?? null,
                $localRuntime ? \php_uname('m') : null,
            ]),
            'php_version' => self::firstNonEmptyString([
                $endpoint['php_version'] ?? null,
                $localRuntime ? \PHP_VERSION : null,
            ]),
            'event_loop_driver' => $eventLoopDriver,
            'event_extension_version' => $eventExtensionVersion,
            'ssl_enabled' => (bool)($endpoint['ssl_enabled'] ?? false),
            'ssl_engine' => $sslEngine,
            'http_protocols' => $httpProtocols,
            'http_preferred_protocol' => $httpPreferredProtocol,
            'protocol_edge_enabled' => $protocolEdgeEnabled,
            'tls_session_resumption' => $tlsSessionResumption,
            'policy_compatible' => $policyCompatible,
            'policy_digest' => self::firstNonEmptyString([
                $endpoint['policy_digest'] ?? null,
                $endpoint['runtime_policy_digest'] ?? null,
            ]),
            'container_registry_digest' => self::firstNonEmptyString([
                $endpoint['container_registry_digest'] ?? null,
            ]),
            'tls_key_exchange_profile' => self::firstNonEmptyString([
                $endpoint['tls_key_exchange_profile'] ?? null,
                $endpoint['ssl_key_exchange_profile'] ?? null,
            ]),
        ];

        return new self($metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    private static function inferLegacyTopology(array $endpoint): ?string
    {
        $topology = self::firstNonEmptyString([
            $endpoint['effective_topology'] ?? null,
            $endpoint['topology'] ?? null,
            $endpoint['runtime_topology'] ?? null,
        ]);
        if ($topology !== null) {
            return $topology;
        }

        $mode = \strtolower(\trim((string)($endpoint['master_mode'] ?? '')));
        $listener = \strtolower(\trim((string)($endpoint['direct_listener_mode'] ?? '')));
        if ((bool)($endpoint['dispatcher_enabled'] ?? false)) {
            return 'dispatcher';
        }
        if ((bool)($endpoint['direct_reuse_port'] ?? false)
            || \in_array($listener, ['reuseport', 'shared_fd'], true)
            || \in_array($mode, ['direct', 'linux-direct'], true)) {
            return 'direct';
        }

        return $mode !== '' ? $mode : null;
    }

    /**
     * @param mixed[] $values
     */
    private static function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $item = \trim((string)$item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return \array_values(\array_unique($result));
    }
}
