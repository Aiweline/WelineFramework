<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\Edge\EdgeAdapterInterface;

/**
 * Separates immutable startup intent from the currently proven serving edge.
 */
final class GatewayStartupRuntimeView
{
    public const SOURCE_GATEWAY = 'gateway';
    public const SOURCE_FALLBACK_WLS = 'fallback_wls';
    public const SOURCE_AUTO_NATIVE_WLS = 'auto_native_wls';
    public const SOURCE_PURE_WLS = 'pure_wls';
    public const SOURCE_GATEWAY_PENDING = 'gateway_pending';
    public const SOURCE_MANAGED_NGINX = 'managed_nginx';
    public const SOURCE_UNKNOWN = 'unknown';

    public const READY_ACTION_NONE = 'none';
    public const READY_ACTION_REGISTER_GATEWAY = 'register_gateway';
    public const READY_ACTION_START_MANAGED_NGINX = 'start_managed_nginx';
    public const READY_ACTION_REJECT = 'reject';

    /**
     * @param array<string,mixed> $endpoint
     * @return array{source:string,ready_action:string,public_proven:bool,requested_mode:string,join_state:string,native_edge_state:string}
     */
    public static function resolve(
        array $endpoint,
        bool $explicitNoNginx = false,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return self::resolveObserved(
            $endpoint,
            GatewayRuntimeServingProjection::gatewayIsServing(
                $endpoint,
                $deadlineMonotonic,
            ),
            GatewayRuntimeServingProjection::fallbackServingEndpoint(
                $endpoint,
                $deadlineMonotonic,
            ) !== null
                || GatewayRuntimeServingProjection::fallbackServingObservation(
                    $endpoint,
                    $deadlineMonotonic,
                ) !== null,
            $explicitNoNginx,
        );
    }

    /**
     * Pure decision surface. Projection booleans must come from
     * GatewayRuntimeServingProjection in production.
     *
     * @param array<string,mixed> $endpoint
     * @return array{source:string,ready_action:string,public_proven:bool,requested_mode:string,join_state:string,native_edge_state:string}
     */
    public static function resolveObserved(
        array $endpoint,
        bool $gatewayServing,
        bool $fallbackServing,
        bool $explicitNoNginx = false,
    ): array {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $requested = \strtolower(\trim((string)(
            $gateway['requested_mode'] ?? $gateway['mode'] ?? ''
        )));
        $mode = \strtolower(\trim((string)($gateway['mode'] ?? '')));
        $adapter = \strtolower(\trim((string)(
            $endpoint['edge_adapter'] ?? ''
        )));
        $join = \is_array($gateway['join_backend'] ?? null)
            ? $gateway['join_backend']
            : [];
        $native = \is_array($gateway['native_edge'] ?? null)
            ? $gateway['native_edge']
            : [];
        $joinState = \strtoupper(\trim((string)($join['state'] ?? '')));
        $nativeState = \strtoupper(\trim((string)(
            $native['state'] ?? ($requested === GatewayStartupDecision::MODE_AUTO
                ? 'ACTIVE'
                : 'NOT_APPLICABLE')
        )));

        if ($gatewayServing) {
            return self::view(
                self::SOURCE_GATEWAY,
                self::READY_ACTION_NONE,
                true,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        if ($fallbackServing) {
            return self::view(
                self::SOURCE_FALLBACK_WLS,
                self::READY_ACTION_NONE,
                true,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        if ($explicitNoNginx) {
            return self::view(
                self::SOURCE_PURE_WLS,
                self::READY_ACTION_NONE,
                false,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        if ($mode === GatewayStartupDecision::MODE_GATEWAY
            && \in_array($requested, [
                GatewayStartupDecision::MODE_AUTO,
                GatewayStartupDecision::MODE_GATEWAY,
            ], true)
            && \hash_equals(
                GatewayPaths::PROTOCOL,
                (string)($gateway['protocol'] ?? ''),
            )
        ) {
            return self::view(
                self::SOURCE_GATEWAY_PENDING,
                self::READY_ACTION_REGISTER_GATEWAY,
                false,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        if ($requested === GatewayStartupDecision::MODE_AUTO
            && $adapter === EdgeAdapterInterface::NAME_WLS
        ) {
            // Auto fallback remains the active local surface until an exact
            // fresh gateway projection is published. If native drain already
            // completed but that projection is unavailable, fail closed and
            // do not invent either public address.
            $source = $nativeState === 'DRAINED'
                ? self::SOURCE_UNKNOWN
                : self::SOURCE_AUTO_NATIVE_WLS;
            return self::view(
                $source,
                $source === self::SOURCE_UNKNOWN
                    ? self::READY_ACTION_REJECT
                    : self::READY_ACTION_NONE,
                false,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        if ($requested === GatewayStartupDecision::MODE_WLS
            && $adapter === EdgeAdapterInterface::NAME_WLS
        ) {
            return self::view(
                self::SOURCE_PURE_WLS,
                self::READY_ACTION_NONE,
                false,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        // 托管 Nginx 边缘的判据是「实际解析出的 mode 是 legacy」，而不是「操作者
        // 显式写了 legacy」。auto 在宿主无 Nginx 且本项目托管 Nginx 就绪时会解析成
        // legacy（见 GatewayStartupDecision::decide 的第三出口），它同样需要一个
        // 受管的公网发布动作；若这里只认 requested=legacy，auto 就会掉进下面的
        // SOURCE_UNKNOWN + REJECT，把已经正确解析出的托管 Nginx 边缘判成矛盾状态。
        // auto→gateway 仍在上面第 121 行被 gateway_pending 分支接走；即使其协议
        // 证明缺失而落到这里，$mode 也是 gateway 而非 legacy，依旧 fail closed。
        if ($adapter === EdgeAdapterInterface::NAME_NGINX
            && $mode === GatewayStartupDecision::MODE_LEGACY
            && \in_array($requested, [
                GatewayStartupDecision::MODE_LEGACY,
                GatewayStartupDecision::MODE_AUTO,
            ], true)
        ) {
            return self::view(
                self::SOURCE_MANAGED_NGINX,
                self::READY_ACTION_START_MANAGED_NGINX,
                false,
                $requested,
                $joinState,
                $nativeState,
            );
        }
        return self::view(
            self::SOURCE_UNKNOWN,
            self::READY_ACTION_REJECT,
            false,
            $requested,
            $joinState,
            $nativeState,
        );
    }

    /**
     * @return array{source:string,ready_action:string,public_proven:bool,requested_mode:string,join_state:string,native_edge_state:string}
     */
    private static function view(
        string $source,
        string $readyAction,
        bool $publicProven,
        string $requested,
        string $joinState,
        string $nativeState,
    ): array {
        return [
            'source' => $source,
            'ready_action' => $readyAction,
            'public_proven' => $publicProven,
            'requested_mode' => $requested,
            'join_state' => $joinState,
            'native_edge_state' => $nativeState,
        ];
    }
}
