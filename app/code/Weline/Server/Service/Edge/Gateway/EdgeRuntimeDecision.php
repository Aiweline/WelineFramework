<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\Edge\EdgeAdapterInterface;

/**
 * Immutable WLS 2.0 edge decision shared by Start, Master and providers.
 */
final class EdgeRuntimeDecision
{
    public const SCOPE_HOST_GATEWAY = 'host_gateway';
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_LEGACY = 'legacy';

    /**
     * @param array<string,mixed> $gateway
     * @param array<string,mixed> $portLease
     */
    public function __construct(
        public readonly string $adapter,
        public readonly string $requestedMode,
        public readonly string $mode,
        public readonly string $scope,
        public readonly string $source,
        public readonly string $reason,
        public readonly string $fallbackReason = '',
        public readonly int $fallbackPort = 0,
        public readonly array $gateway = [],
        public readonly array $portLease = [],
    ) {
        if (!\in_array($adapter, [
            EdgeAdapterInterface::NAME_NGINX,
            EdgeAdapterInterface::NAME_WLS,
        ], true)) {
            throw new \InvalidArgumentException('WLS edge decision adapter must be nginx or wls.');
        }
        if (!\in_array($requestedMode, GatewayStartupDecision::MODES, true)
            || !\in_array($mode, [
                GatewayStartupDecision::MODE_GATEWAY,
                GatewayStartupDecision::MODE_WLS,
                GatewayStartupDecision::MODE_LEGACY,
            ], true)
        ) {
            throw new \InvalidArgumentException('WLS edge decision contains an invalid mode.');
        }
        if ($source !== \trim($source)
            || $reason !== \trim($reason)
            || $fallbackReason !== \trim($fallbackReason)
            || $source === ''
            || $reason === ''
            || \strlen($source) > 128
            || \strlen($reason) > 256
            || \strlen($fallbackReason) > 256
            || \preg_match('/[\x00-\x1f\x7f]/D', $source . $reason . $fallbackReason) === 1
        ) {
            throw new \InvalidArgumentException(
                'WLS edge decision source or reason is empty, unbounded or unsafe.'
            );
        }
        try {
            $variableState = GatewayClient::canonicalJson([
                'gateway' => $gateway,
                'port_lease' => $portLease,
            ]);
        } catch (\Throwable $throwable) {
            throw new \InvalidArgumentException(
                'WLS edge decision variable state is not serializable.',
                0,
                $throwable,
            );
        }
        if (\strlen($variableState) > 65_536) {
            throw new \InvalidArgumentException(
                'WLS edge decision variable state exceeds its fixed bound.'
            );
        }
        if (!\in_array($scope, [
            self::SCOPE_HOST_GATEWAY,
            self::SCOPE_PROJECT,
            self::SCOPE_LEGACY,
        ], true)) {
            throw new \InvalidArgumentException('WLS edge decision contains an invalid scope.');
        }
        if ($mode === GatewayStartupDecision::MODE_GATEWAY
            && ($adapter !== EdgeAdapterInterface::NAME_NGINX
                || $scope !== self::SCOPE_HOST_GATEWAY)
        ) {
            throw new \InvalidArgumentException('Gateway mode requires the host_gateway Nginx scope.');
        }
        if ($mode === GatewayStartupDecision::MODE_WLS
            && ($adapter !== EdgeAdapterInterface::NAME_WLS || $scope !== self::SCOPE_PROJECT)
        ) {
            throw new \InvalidArgumentException('Pure WLS mode requires the project WLS scope.');
        }
        if ($fallbackPort !== 0 && ($fallbackPort < 20000 || $fallbackPort > 29999)) {
            throw new \InvalidArgumentException('WLS fallback port must be zero or in 20000..29999.');
        }
        if ($requestedMode === GatewayStartupDecision::MODE_AUTO
            && $mode === GatewayStartupDecision::MODE_WLS
            && \trim($fallbackReason) === ''
        ) {
            throw new \InvalidArgumentException('Automatic pure-WLS fallback requires a reason.');
        }
        if ($portLease !== []) {
            $leasePort = (int)($portLease['port'] ?? 0);
            $allocationScope = (string)($portLease['allocation_scope'] ?? '');
            $scopeIsValid = ($allocationScope === 'stable_range'
                    && $leasePort >= 20000
                    && $leasePort <= 29999
                    && $fallbackPort === $leasePort)
                || ($allocationScope === 'exact'
                    && $leasePort >= 1
                    && $leasePort <= 65535
                    && $fallbackPort === 0);
            if (!$scopeIsValid
                || (int)($portLease['schema_version'] ?? 0) !== 5
                || !\hash_equals('RESERVED', (string)($portLease['state'] ?? ''))
                || \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)($portLease['lease_id'] ?? ''),
                ) !== 1
                || \preg_match(
                    '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',
                    (string)($portLease['instance'] ?? ''),
                ) !== 1
                || \filter_var(
                    (string)($portLease['bind_host'] ?? ''),
                    FILTER_VALIDATE_IP,
                ) === false
            ) {
                throw new \InvalidArgumentException('WLS public port lease is invalid.');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'requested_mode' => $this->requestedMode,
            'mode' => $this->mode,
            'scope' => $this->scope,
            'source' => $this->source,
            'reason' => $this->reason,
            'fallback_reason' => $this->fallbackReason,
            'fallback_port' => $this->fallbackPort,
            'gateway' => $this->gateway,
            'port_lease' => $this->portLease,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            adapter: \strtolower(\trim((string)($data['adapter'] ?? ''))),
            requestedMode: \strtolower(\trim((string)($data['requested_mode'] ?? ''))),
            mode: \strtolower(\trim((string)($data['mode'] ?? ''))),
            scope: \strtolower(\trim((string)($data['scope'] ?? ''))),
            source: \trim((string)($data['source'] ?? '')),
            reason: \trim((string)($data['reason'] ?? '')),
            fallbackReason: \trim((string)($data['fallback_reason'] ?? '')),
            fallbackPort: (int)($data['fallback_port'] ?? 0),
            gateway: \is_array($data['gateway'] ?? null) ? $data['gateway'] : [],
            portLease: \is_array($data['port_lease'] ?? null) ? $data['port_lease'] : [],
        );
    }

    public function isGateway(): bool
    {
        return $this->mode === GatewayStartupDecision::MODE_GATEWAY;
    }

    public function isAutoFallback(): bool
    {
        return $this->requestedMode === GatewayStartupDecision::MODE_AUTO
            && $this->mode === GatewayStartupDecision::MODE_WLS;
    }
}
