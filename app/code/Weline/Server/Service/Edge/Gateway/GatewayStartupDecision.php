<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\Edge\Nginx\ManagedNginxEdgeAvailability;

/**
 * Resolves the public WLS 2.0 edge intent before WLS binds its backend.
 *
 * auto 的三个出口（按优先级）：命中可信宿主 Weline Gateway → 宿主无 Nginx 且本项目
 * 托管 Nginx 已安装 → 自建项目级托管 Nginx；都不成立才回退纯 WLS。
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

    /** @var resource|null */
    private mixed $reservedListener = null;

    private const DEFAULT_RESERVATION_BUDGET_SECONDS = 5.0;

    private ?GatewayPortLeaseAllocator $ports;

    private GatewayStartupBootstrapperInterface $bootstrapper;

    private ManagedEdgeAvailabilityInterface $managedEdge;

    public function __construct(
        private readonly GatewayStartupHostInterface $gateway = new GatewayHostManager(),
        ?GatewayPortLeaseAllocator $ports = null,
        ?GatewayStartupBootstrapperInterface $bootstrapper = null,
        ?ManagedEdgeAvailabilityInterface $managedEdge = null,
    ) {
        $this->ports = $ports;
        $this->bootstrapper = $bootstrapper
            ?? new GatewayInitialBootstrapCoordinator();
        $this->managedEdge = $managedEdge
            ?? new ManagedNginxEdgeAvailability();
    }

    public function decide(
        string $requested,
        string $instanceName,
        bool $portExplicit,
        string $source = 'runtime',
        string $bindHost = '127.0.0.1',
        ?int $exactPort = null,
        bool $reserveListener = true,
        ?float $deadlineMonotonic = null,
    ): EdgeRuntimeDecision {
        $deadlineMonotonic = self::operationDeadline($deadlineMonotonic);
        $requested = \strtolower(\trim($requested));
        $source = self::boundedDecisionText($source, 128, 'runtime');
        if (!\in_array($requested, self::MODES, true)) {
            throw new \InvalidArgumentException(
                'WLS edge mode must be auto, gateway, wls or legacy.'
            );
        }
        if ($portExplicit && ($exactPort === null || $exactPort < 1 || $exactPort > 65535)) {
            throw new \InvalidArgumentException(
                'Explicit WLS port intent requires an exact port between 1 and 65535.'
            );
        }
        if ($requested === self::MODE_WLS) {
            if (!$reserveListener) {
                return new EdgeRuntimeDecision(
                    adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
                    requestedMode: $requested,
                    mode: self::MODE_WLS,
                    scope: EdgeRuntimeDecision::SCOPE_PROJECT,
                    source: $source,
                    reason: 'Pure WLS was explicitly requested.',
                );
            }
            $lease = $this->reservePublicPort(
                $instanceName,
                $bindHost,
                $portExplicit ? $exactPort : null,
                $deadlineMonotonic,
            );
            $fallbackPort = self::publicFallbackPortFromLease($lease);
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
                requestedMode: $requested,
                mode: self::MODE_WLS,
                scope: EdgeRuntimeDecision::SCOPE_PROJECT,
                source: $source,
                reason: 'Pure WLS was explicitly requested.',
                fallbackPort: $fallbackPort,
                portLease: $lease,
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

        // Startup discovers a trusted gateway first. Only a host classified
        // INSTALL_REQUIRED may enter the signed-package bootstrap election;
        // upgrade and repair remain explicit administrator commands.
        // When auto cannot join and falls back to pure WLS, an explicit `-p`
        // is honored as the degraded public listen port. Successful gateway
        // join still treats `-p` as backend intent in Start.php and never
        // reaches this reservation.
        try {
            $statusDeadline = \min(
                $deadlineMonotonic,
                (\hrtime(true) / 1_000_000_000) + 5.0,
            );
            $observed = $this->gateway->status(5.0, $statusDeadline);
            if (!(($observed['ok'] ?? false) === true)) {
                // prepare() accepts the already-read status and performs only
                // read-only host classification. It never binds 80/443, asks
                // for credentials or stops an owner.
                $observed = $this->gateway->prepare(
                    $observed,
                    $deadlineMonotonic,
                );
            }
        } catch (\Throwable $throwable) {
            $observed = [
                'ok' => false,
                'ready' => false,
                'state' => 'GATEWAY_UNAVAILABLE',
                'reason' => self::boundedDecisionText(
                    $throwable->getMessage(),
                    256,
                    'Gateway discovery failed.',
                ),
                'data_plane' => ['running' => false],
            ];
        }
        $gatewayObservation = self::boundedGatewayObservation($observed);
        $controlAcceptsRegistration = GatewayHostManager::controlPlaneAcceptsRegistration(
            $observed,
        );
        $publicDataPlanePresent = ($observed['data_plane']['running'] ?? false) === true
            && (string)($observed['state'] ?? '') !== 'DATA_PLANE_DOWN';
        if (self::shouldJoinTrustedGateway(
            $requested,
            $controlAcceptsRegistration,
        ) && ($publicDataPlanePresent || $requested === self::MODE_GATEWAY)) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                requestedMode: $requested,
                mode: self::MODE_GATEWAY,
                scope: EdgeRuntimeDecision::SCOPE_HOST_GATEWAY,
                source: $source,
                reason: ($observed['ready'] ?? false) === true
                    ? 'Trusted WLS 2.0 host gateway is ready.'
                    : ($publicDataPlanePresent
                        ? 'Trusted WLS 2.0 host gateway is accepting project replay while tenant routes recover.'
                        : 'Trusted WLS 2.0 control plane is accepting explicit project replay while its public data plane recovers; startup must still pass bounded route publication.'),
                gateway: $gatewayObservation,
            );
        }
        if (\hash_equals('INSTALL_REQUIRED', (string)($observed['state'] ?? ''))) {
            try {
                $observed = $this->bootstrapper->bootstrap(
                    $observed,
                    $deadlineMonotonic,
                );
            } catch (\Throwable $throwable) {
                $observed = [
                    'ok' => false,
                    'ready' => false,
                    'state' => 'BOOTSTRAP_UNAVAILABLE',
                    'reason' => self::boundedDecisionText(
                        $throwable->getMessage(),
                        256,
                        'Initial gateway bootstrap failed.',
                    ),
                    'data_plane' => ['running' => false],
                ];
            }
            $gatewayObservation = self::boundedGatewayObservation($observed);
            $controlAcceptsRegistration = GatewayHostManager::controlPlaneAcceptsRegistration(
                $observed,
            );
            $publicDataPlanePresent = ($observed['data_plane']['running'] ?? false) === true
                && (string)($observed['state'] ?? '') !== 'DATA_PLANE_DOWN';
            if (self::shouldJoinTrustedGateway(
                $requested,
                $controlAcceptsRegistration,
            ) && ($publicDataPlanePresent || $requested === self::MODE_GATEWAY)) {
                return new EdgeRuntimeDecision(
                    adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                    requestedMode: $requested,
                    mode: self::MODE_GATEWAY,
                    scope: EdgeRuntimeDecision::SCOPE_HOST_GATEWAY,
                    source: $source,
                    reason: ($observed['ready'] ?? false) === true
                        ? 'The first project established and joined the trusted WLS 2.0 host gateway.'
                        : 'The trusted WLS 2.0 control plane accepted explicit project replay while its data plane recovers.',
                    gateway: $gatewayObservation,
                );
            }
        }
        if ($requested === self::MODE_GATEWAY) {
            throw new \RuntimeException(
                'Explicit gateway mode failed ['
                    . self::boundedDecisionText(
                        (string)($observed['state'] ?? ''),
                        64,
                        'GATEWAY_UNAVAILABLE',
                    )
                    . ']: '
                    . self::boundedDecisionText(
                        (string)($observed['reason'] ?? ''),
                        256,
                        'gateway unavailable',
                    )
            );
        }

        // auto 的第三出口：宿主既没有可信 Weline Gateway，也没有自己的 Nginx，
        // 而本项目托管 Nginx 已安装 —— 此时由 WLS 自建项目级 Nginx 边缘，
        // 不再退到高端口纯 WLS。显式 gateway 模式永不落到这里（上面已抛错）。
        if ($requested === self::MODE_AUTO
            && !$this->managedEdge->hostNginxOccupied()
            && $this->managedEdge->managedNginxReady()
        ) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
                requestedMode: $requested,
                mode: self::MODE_LEGACY,
                scope: EdgeRuntimeDecision::SCOPE_LEGACY,
                source: $source,
                reason: 'Host Nginx is absent and the project-managed Nginx is installed; '
                    . 'WLS owns the project-scoped Nginx edge.',
                gateway: $gatewayObservation,
            );
        }

        // 回退纯 WLS 时把「为什么没用托管 Nginx」也写进原因，否则运维无法区分
        // 「宿主已占用边缘」与「托管 Nginx 没装」。宿主网关那段先压到 160 字节，
        // 给托管 Nginx 的说明留出预算，避免尾部被整体截断。
        $gatewayFallbackReason = self::boundedDecisionText(
            (string)($observed['state'] ?? 'GATEWAY_UNAVAILABLE') . ': '
                . (string)($observed['reason'] ?? 'Gateway unavailable.'),
            160,
            'GATEWAY_UNAVAILABLE: Gateway unavailable.',
        );
        $fallbackReason = self::boundedDecisionText(
            $requested === self::MODE_AUTO
                ? $gatewayFallbackReason
                    . ' | managed Nginx edge unavailable: '
                    . $this->managedEdge->unavailableReason()
                : $gatewayFallbackReason,
            256,
            $gatewayFallbackReason,
        );
        if (!$reserveListener) {
            return new EdgeRuntimeDecision(
                adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
                requestedMode: $requested,
                mode: self::MODE_WLS,
                scope: EdgeRuntimeDecision::SCOPE_PROJECT,
                source: $source,
                reason: $fallbackReason,
                fallbackReason: $fallbackReason,
                gateway: $gatewayObservation,
            );
        }
        $lease = $this->reservePublicPort(
            $instanceName,
            $bindHost,
            $portExplicit ? $exactPort : null,
            $deadlineMonotonic,
        );
        $fallbackPort = self::publicFallbackPortFromLease($lease);
        return new EdgeRuntimeDecision(
            adapter: \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
            requestedMode: $requested,
            mode: self::MODE_WLS,
            scope: EdgeRuntimeDecision::SCOPE_PROJECT,
            source: $source,
            reason: $fallbackReason,
            fallbackReason: $fallbackReason,
            fallbackPort: $fallbackPort,
            gateway: $gatewayObservation,
            portLease: $lease,
        );
    }

    /**
     * Bind a previously planned pure-WLS decision after an old generation has
     * been stopped and its listener set has been proven released. The exact
     * port prevents a restart from silently changing its public address.
     */
    public function materializePublicListener(
        EdgeRuntimeDecision $decision,
        string $instanceName,
        string $bindHost,
        int $exactPort,
        ?float $deadlineMonotonic = null,
    ): EdgeRuntimeDecision {
        $deadlineMonotonic = self::operationDeadline($deadlineMonotonic);
        if ($decision->mode !== self::MODE_WLS
            || $decision->portLease !== []
            || $exactPort < 1
            || $exactPort > 65535
            || \is_resource($this->reservedListener)
        ) {
            throw new \RuntimeException(
                'Only one deferred pure-WLS listener may be materialized per startup decision.',
            );
        }
        $lease = $this->reservePublicPort(
            $instanceName,
            $bindHost,
            $exactPort,
            $deadlineMonotonic,
        );
        $fallbackPort = self::publicFallbackPortFromLease($lease);
        return new EdgeRuntimeDecision(
            adapter: $decision->adapter,
            requestedMode: $decision->requestedMode,
            mode: $decision->mode,
            scope: $decision->scope,
            source: $decision->source,
            reason: $decision->reason,
            fallbackReason: $decision->fallbackReason,
            fallbackPort: $fallbackPort,
            gateway: $decision->gateway,
            portLease: $lease,
        );
    }

    /**
     * Return the POSIX listener retained during automatic public-port
     * selection. Ownership transfers to the caller.
     *
     * @return resource|null
     */
    public function takeReservedListener(): mixed
    {
        $listener = $this->reservedListener;
        $this->reservedListener = null;
        return \is_resource($listener) ? $listener : null;
    }

    private static function boundedDecisionText(
        string $value,
        int $maximumBytes,
        string $fallback,
    ): string {
        $value = \trim(\str_replace("\0", '', $value));
        $value = \preg_replace('/[\x01-\x1f\x7f]+/', ' ', $value) ?? '';
        $value = \trim(\preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            $value = $fallback;
        }
        $value = \substr($value, 0, $maximumBytes);
        while ($value !== '' && \json_encode($value) === false) {
            $value = \substr($value, 0, -1);
        }
        return $value !== '' ? $value : $fallback;
    }

    private static function shouldJoinTrustedGateway(
        string $requested,
        bool $controlAcceptsRegistration,
    ): bool {
        return $controlAcceptsRegistration
            && \in_array($requested, [self::MODE_AUTO, self::MODE_GATEWAY], true);
    }

    /**
     * Persist only the stable discovery facts consumed by server:start. A
     * project-own status response can contain hundreds of routes and must not
     * be copied wholesale into every instance configuration.
     *
     * @param array<string,mixed> $observed
     * @return array<string,mixed>
     */
    private static function boundedGatewayObservation(array $observed): array
    {
        $epoch = \strtolower(\trim((string)($observed['epoch'] ?? '')));
        return [
            'ok' => ($observed['ok'] ?? false) === true,
            'ready' => ($observed['ready'] ?? false) === true,
            'control_plane_ready' => ($observed['control_plane_ready'] ?? false) === true,
            'release_ready' => ($observed['release_ready'] ?? false) === true,
            'broker_ready' => ($observed['broker_ready'] ?? false) === true,
            'supervisor_ready' => ($observed['supervisor_ready'] ?? false) === true,
            'protocol' => self::boundedDecisionText(
                (string)($observed['protocol'] ?? ''),
                64,
                'unknown',
            ),
            'state' => self::boundedDecisionText(
                (string)($observed['state'] ?? ''),
                64,
                'UNKNOWN',
            ),
            'epoch' => \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) === 1
                ? $epoch
                : '',
            'public_http' => self::boundedPort($observed['public_http'] ?? 0),
            'public_https' => self::boundedPort($observed['public_https'] ?? 0),
            'reason' => self::boundedDecisionText(
                (string)($observed['reason'] ?? ''),
                256,
                'status unavailable',
            ),
        ];
    }

    private static function boundedPort(mixed $value): int
    {
        $port = \is_int($value) ? $value : 0;
        return $port >= 1 && $port <= 65535 ? $port : 0;
    }

    /** @param array<string,mixed> $lease */
    private static function publicFallbackPortFromLease(array $lease): int
    {
        // EdgeRuntimeDecision.fallbackPort only advertises stable_range
        // (20000–29999). Exact `-p` reservations live in port_lease + Start
        // config.port and must keep fallbackPort=0.
        if ((string)($lease['allocation_scope'] ?? '') !== 'stable_range') {
            return 0;
        }
        return self::boundedPort($lease['port'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function reservePublicPort(
        string $instanceName,
        string $bindHost,
        ?int $exactPort = null,
        ?float $deadlineMonotonic = null,
    ): array
    {
        $deadlineMonotonic = self::operationDeadline($deadlineMonotonic);
        if (\is_resource($this->reservedListener)) {
            throw new \RuntimeException(
                'This WLS startup decision already owns a retained public listener.',
            );
        }
        $bindHost = \strtolower(\trim($bindHost, " \t\n\r\0\x0B[]"));
        if ($bindHost === '' || $bindHost === 'localhost') {
            $bindHost = '127.0.0.1';
        }
        $packed = @\inet_pton($bindHost);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException(
                'Automatic WLS public port selection requires a literal bind address.'
            );
        }
        $bindHost = \strtolower($normalized);
        // Default construction is intentionally lazy: a deferred restart may
        // spend its drain budget before materializing the listener. Create one
        // bounded allocator when this decision actually reserves, not when the
        // decision object was instantiated and not once per candidate port.
        $ports = $this->ports;
        if ($ports === null) {
            $ports = new GatewayPortLeaseAllocator(
                operationDeadlineMonotonic: $deadlineMonotonic,
            );
            $this->ports = $ports;
        }
        $lease = $ports->reserveBound(
            $instanceName,
            static function (int $port) use ($bindHost): mixed {
                $address = 'tcp://'
                    . (\str_contains($bindHost, ':') ? '[' . $bindHost . ']' : $bindHost)
                    . ':' . $port;
                return @\stream_socket_server(
                    $address,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
            },
            $bindHost,
            true,
            $exactPort,
        );
        $this->reservedListener = $ports->takeRetainedBoundSocket(
            (string)$lease['lease_id'],
        );
        if (!\is_resource($this->reservedListener)) {
            throw new \RuntimeException(
                'Reserved WLS public port did not retain its listening socket.'
            );
        }
        return $lease;
    }

    private static function operationDeadline(?float $deadlineMonotonic): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if ($deadlineMonotonic === null) {
            return $now + self::DEFAULT_RESERVATION_BUDGET_SECONDS;
        }
        if (!\is_finite($deadlineMonotonic) || $deadlineMonotonic <= $now) {
            throw new \RuntimeException(
                'WLS edge startup decision deadline was exhausted.',
            );
        }
        return $deadlineMonotonic;
    }
}
