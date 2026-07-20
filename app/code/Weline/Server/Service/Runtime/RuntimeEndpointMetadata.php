<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Read-only, report-safe projection of one persisted WLS endpoint record.
 *
 * Schema v4 accepts exactly one topology fact: runtime_selection. Older
 * endpoint schemas and flattened topology projections are rejected.
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
        $selection = RuntimeSelection::fromEndpoint($endpoint);
        $schemaVersion = RuntimeSelection::ENDPOINT_SCHEMA_VERSION;

        $host = \strtolower(\trim((string)($endpoint['host'] ?? '')));
        $localRuntime = \in_array($host, ['', '127.0.0.1', 'localhost', '::1', '0.0.0.0', '::'], true);
        $metadata = [
            'metadata_source' => 'endpoint_schema_v4',
            'endpoint_schema_version' => $schemaVersion,
            'runtime_selection' => $selection->toArray(),
            'architecture' => self::firstNonEmptyString([
                $endpoint['architecture'] ?? null,
                $localRuntime ? \php_uname('m') : null,
            ]),
            'php_version' => self::firstNonEmptyString([
                $endpoint['php_version'] ?? null,
                $localRuntime ? \PHP_VERSION : null,
            ]),
            'event_extension_version' => self::firstNonEmptyString([
                $endpoint['event_extension_version'] ?? null,
                $localRuntime && \extension_loaded('event') ? (\phpversion('event') ?: null) : null,
            ]),
            'ssl_enabled' => (bool)($endpoint['ssl_enabled'] ?? false),
            'policy_digest' => self::firstNonEmptyString([
                $endpoint['policy_digest'] ?? null,
            ]),
            'container_registry_digest' => self::firstNonEmptyString([
                $endpoint['container_registry_digest'] ?? null,
            ]),
            'tls_key_exchange_profile' => self::firstNonEmptyString([
                $endpoint['tls_key_exchange_profile'] ?? null,
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
}
