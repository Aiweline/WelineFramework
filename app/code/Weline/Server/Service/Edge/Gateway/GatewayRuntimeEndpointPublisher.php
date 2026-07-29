<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\ServerInstanceManager;

/**
 * Publishes the observed edge surface without changing project-owned route,
 * certificate, launch or backend identity fields.
 */
final class GatewayRuntimeEndpointPublisher
{
    public function __construct(
        private readonly ?ServerInstanceManager $instances = null,
    ) {
    }

    /**
     * @param array<string,mixed> $status
     */
    public function publishHealthy(
        string $instanceName,
        array $status,
        string $nativeEdgeState,
    ): bool {
        self::assertHealthyObservation($status);
        $instances = $this->instances ?? new ServerInstanceManager();
        $file = $instances->getInstanceFile($instanceName);
        $current = $instances->getRawInstanceData($instanceName);
        if (!\is_array($current)) {
            return false;
        }
        if (self::healthyProjectionIsCurrent($current, $status, $nativeEdgeState)) {
            return true;
        }
        $now = \time();

        return ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static fn (array $endpoint): array => self::applyHealthyObservation(
                $endpoint,
                $status,
                $nativeEdgeState,
                $now,
            ),
        );
    }

    public function publishFallbackActive(
        string $instanceName,
        int $httpsPort,
        string $reason,
    ): bool {
        if ($httpsPort < 20000 || $httpsPort > 29999) {
            throw new \RuntimeException('Fallback HTTPS port is outside the project lease range.');
        }
        $instances = $this->instances ?? new ServerInstanceManager();
        $file = $instances->getInstanceFile($instanceName);
        $current = $instances->getRawInstanceData($instanceName);
        if (!\is_array($current)) {
            return false;
        }
        $gateway = \is_array($current['gateway'] ?? null) ? $current['gateway'] : [];
        if ((string)($gateway['mode'] ?? '') === 'wls'
            && (string)($gateway['fallback_state'] ?? '') === 'DEGRADED_WLS'
            && (int)($gateway['public_https'] ?? 0) === $httpsPort
            && (string)($gateway['degraded_reason'] ?? '') === $reason
        ) {
            return true;
        }
        $now = \time();

        return ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static fn (array $endpoint): array => self::applyFallbackObservation(
                $endpoint,
                $httpsPort,
                $reason,
                $now,
            ),
        );
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function applyHealthyObservation(
        array $endpoint,
        array $status,
        string $nativeEdgeState,
        int $now,
    ): array {
        self::assertHealthyObservation($status);
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $nativeState = \strtoupper(\trim($nativeEdgeState));
        $gateway['mode'] = 'gateway';
        $gateway['protocol'] = GatewayPaths::PROTOCOL;
        $gateway['epoch'] = \strtolower((string)$status['epoch']);
        $gateway['public_http'] = (int)$status['public_http'];
        $gateway['public_https'] = (int)$status['public_https'];
        $gateway['degraded_reason'] = '';
        $gateway['fallback_state'] = match ($nativeState) {
            'DRAINING' => 'NATIVE_EDGE_DRAINING',
            'DRAINED' => 'GATEWAY_ACTIVE',
            default => 'NATIVE_EDGE_STANDBY',
        };
        $gateway['runtime_generation'] = \max(0, (int)($status['generation'] ?? 0));
        $gateway['runtime_observed_at'] = \gmdate(DATE_ATOM, $now);
        $gateway['runtime_observed_timestamp'] = $now;
        $endpoint['gateway'] = $gateway;

        return $endpoint;
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    public static function applyFallbackObservation(
        array $endpoint,
        int $httpsPort,
        string $reason,
        int $now,
    ): array {
        if ($httpsPort < 20000 || $httpsPort > 29999) {
            throw new \RuntimeException('Fallback HTTPS port is outside the project lease range.');
        }
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $gateway['mode'] = 'wls';
        $gateway['public_http'] = 0;
        $gateway['public_https'] = $httpsPort;
        $gateway['degraded_reason'] = \substr(\trim($reason), 0, 256);
        $gateway['fallback_state'] = 'DEGRADED_WLS';
        $gateway['runtime_observed_at'] = \gmdate(DATE_ATOM, $now);
        $gateway['runtime_observed_timestamp'] = $now;
        $endpoint['gateway'] = $gateway;

        return $endpoint;
    }

    /**
     * @param array<string,mixed> $status
     */
    private static function assertHealthyObservation(array $status): void
    {
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $http = (int)($status['public_http'] ?? 0);
        $https = (int)($status['public_https'] ?? 0);
        if (($status['ok'] ?? false) !== true
            || ($status['ready'] ?? false) !== true
            || ($status['supervisor_ready'] ?? false) !== true
            || ($status['data_plane']['running'] ?? false) !== true
            || (string)($status['state'] ?? '') === 'DATA_PLANE_DOWN'
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($status['protocol'] ?? ''))
            || \preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1
            || $http < 1
            || $http > 65535
            || $https < 1
            || $https > 65535
        ) {
            throw new \RuntimeException('Gateway observation is not authenticated and healthy.');
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $status
     */
    private static function healthyProjectionIsCurrent(
        array $endpoint,
        array $status,
        string $nativeEdgeState,
    ): bool {
        $gateway = \is_array($endpoint['gateway'] ?? null) ? $endpoint['gateway'] : [];
        $nativeState = \strtoupper(\trim($nativeEdgeState));
        $fallbackState = match ($nativeState) {
            'DRAINING' => 'NATIVE_EDGE_DRAINING',
            'DRAINED' => 'GATEWAY_ACTIVE',
            default => 'NATIVE_EDGE_STANDBY',
        };

        return (string)($gateway['mode'] ?? '') === 'gateway'
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($gateway['protocol'] ?? ''))
            && \hash_equals(
                \strtolower((string)$status['epoch']),
                \strtolower((string)($gateway['epoch'] ?? '')),
            )
            && (int)($gateway['public_http'] ?? 0) === (int)$status['public_http']
            && (int)($gateway['public_https'] ?? 0) === (int)$status['public_https']
            && (string)($gateway['degraded_reason'] ?? '') === ''
            && (string)($gateway['fallback_state'] ?? '') === $fallbackState
            && (int)($gateway['runtime_generation'] ?? 0)
                === \max(0, (int)($status['generation'] ?? 0));
    }
}
