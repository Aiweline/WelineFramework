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

    public function __construct(
        private readonly GatewayHostManager $gateway = new GatewayHostManager(),
        private readonly GatewayPortLeaseAllocator $ports = new GatewayPortLeaseAllocator(),
    ) {
    }

    /**
     * @return array{requested:string,effective:string,reason:string,fallback_port:int,gateway:array<string,mixed>}
     */
    public function decide(
        string $requested,
        string $instanceName,
        bool $portExplicit,
    ): array {
        $requested = \strtolower(\trim($requested));
        if (!\in_array($requested, [self::MODE_AUTO, self::MODE_GATEWAY, self::MODE_WLS, self::MODE_LEGACY], true)) {
            throw new \InvalidArgumentException('WLS edge mode must be auto, gateway or wls.');
        }
        if ($requested === self::MODE_WLS) {
            return [
                'requested' => $requested,
                'effective' => self::MODE_WLS,
                'reason' => 'Pure WLS was explicitly requested.',
                'fallback_port' => 0,
                'gateway' => [],
            ];
        }
        if ($requested === self::MODE_LEGACY) {
            return [
                'requested' => $requested,
                'effective' => self::MODE_LEGACY,
                'reason' => 'Existing WLS 1.x managed-Nginx instance remains legacy until explicit promotion.',
                'fallback_port' => 0,
                'gateway' => [],
            ];
        }

        $prepared = $this->gateway->prepare();
        if (($prepared['ok'] ?? false)
            && ($prepared['ready'] ?? false)
            && ($prepared['protocol'] ?? '') === GatewayPaths::PROTOCOL
        ) {
            return [
                'requested' => $requested,
                'effective' => self::MODE_GATEWAY,
                'reason' => 'Trusted WLS 2.0 host gateway is ready.',
                'fallback_port' => 0,
                'gateway' => $prepared,
            ];
        }
        if ($requested === self::MODE_GATEWAY) {
            throw new \RuntimeException(
                'Explicit gateway mode failed: ' . (string)($prepared['reason'] ?? 'gateway unavailable')
            );
        }

        return [
            'requested' => $requested,
            'effective' => self::MODE_WLS,
            'reason' => (string)($prepared['state'] ?? 'GATEWAY_UNAVAILABLE') . ': '
                . (string)($prepared['reason'] ?? 'Gateway unavailable.'),
            'fallback_port' => $portExplicit ? 0 : $this->ports->allocate($instanceName),
            'gateway' => $prepared,
        ];
    }
}
