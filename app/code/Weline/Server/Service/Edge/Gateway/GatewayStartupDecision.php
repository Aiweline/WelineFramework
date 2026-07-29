<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Resolves the public WLS 2.0 edge intent before WLS binds its backend.
 */
final class GatewayStartupDecision
{
    public const MODE_AUTO = 'auto';
    public const MODE_GATEWAY = 'gateway';
    public const MODE_WLS = 'wls';
    public const MODE_LEGACY = 'legacy';
    public const MODES = [
        self::MODE_AUTO,
        self::MODE_GATEWAY,
        self::MODE_WLS,
        self::MODE_LEGACY,
    ];

    public function __construct(
        private readonly GatewayHostManager $gateway = new GatewayHostManager(),
        private readonly GatewayPortLeaseAllocator $ports = new GatewayPortLeaseAllocator(),
    ) {
    }

    public function decide(
        string $requested,
        string $instanceName,
        bool $portExplicit,
        string $source = 'runtime',
    ): EdgeRuntimeDecision {
        $requested = \strtolower(\trim($requested));
        if (!\in_array($requested, self::MODES, true)) {
            throw new \InvalidArgumentException('WLS edge mode must be auto, gateway or wls.');
        }
        if ($requested === self::MODE_WLS) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
                requestedMode: $requested,
                mode: self::MODE_WLS,
                scope: EdgeRuntimeDecision::SCOPE_PROJECT,
                source: $source,
                reason: 'Pure WLS was explicitly requested.',
            );
        }
        if ($requested === self::MODE_LEGACY) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                requestedMode: $requested,
                mode: self::MODE_LEGACY,
                scope: EdgeRuntimeDecision::SCOPE_LEGACY,
                source: $source,
                reason: 'Existing WLS 1.x managed-Nginx instance remains legacy until explicit promotion.',
            );
        }

        // Ordinary project startup is discovery/join only. Installation,
        // upgrade and repair remain explicit administrator commands.
        $observed = $this->gateway->status(5.0);
        if (($observed['ok'] ?? false)
            && ($observed['ready'] ?? false)
            && ($observed['supervisor_ready'] ?? false)
            && ($observed['protocol'] ?? '') === GatewayPaths::PROTOCOL
            && (int)($observed['protocol_min'] ?? 0) <= 2
            && (int)($observed['protocol_max'] ?? 0) >= 2
        ) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                requestedMode: $requested,
                mode: self::MODE_GATEWAY,
                scope: EdgeRuntimeDecision::SCOPE_HOST_GATEWAY,
                source: $source,
                reason: 'Trusted WLS 2.0 host gateway is ready.',
                gateway: $observed,
            );
        }
        if ($requested === self::MODE_GATEWAY) {
            throw new \RuntimeException(
                'Explicit gateway mode failed: ' . (string)($observed['reason'] ?? 'gateway unavailable')
            );
        }

        $fallbackReason = (string)($observed['state'] ?? 'GATEWAY_UNAVAILABLE') . ': '
            . (string)($observed['reason'] ?? 'Gateway unavailable.');
        return new EdgeRuntimeDecision(
            adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
            requestedMode: $requested,
            mode: self::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: $source,
            reason: $fallbackReason,
            fallbackReason: $fallbackReason,
            fallbackPort: $portExplicit ? 0 : $this->ports->allocate($instanceName),
            gateway: $observed,
        );
    }
}
