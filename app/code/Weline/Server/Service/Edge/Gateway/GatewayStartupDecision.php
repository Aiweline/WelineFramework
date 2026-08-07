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

    /** @var resource|null */
    private mixed $reservedListener = null;

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
        string $bindHost = '127.0.0.1',
        ?int $exactPort = null,
        bool $reserveListener = true,
    ): EdgeRuntimeDecision {
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
            );
            $fallbackPort = (string)($lease['allocation_scope'] ?? '') === 'stable_range'
                ? (int)($lease['port'] ?? 0)
                : 0;
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

        // Ordinary project startup is discovery/join only. Installation,
        // upgrade and repair remain explicit administrator commands.
        try {
            $observed = $this->gateway->status(5.0);
            if (!(($observed['ok'] ?? false) === true)) {
                // prepare() accepts the already-read status and performs only
                // read-only host classification. Startup never binds 80/443,
                // installs a service, asks for credentials or stops an owner.
                $observed = $this->gateway->prepare($observed);
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
        if ($requested === self::MODE_GATEWAY) {
            throw new \RuntimeException(
                'Explicit gateway mode failed: '
                    . self::boundedDecisionText(
                        (string)($observed['reason'] ?? ''),
                        256,
                        'gateway unavailable',
                    )
            );
        }

        $fallbackReason = self::boundedDecisionText(
            (string)($observed['state'] ?? 'GATEWAY_UNAVAILABLE') . ': '
                . (string)($observed['reason'] ?? 'Gateway unavailable.'),
            256,
            'GATEWAY_UNAVAILABLE: Gateway unavailable.',
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
            null,
        );
        $fallbackPort = (string)($lease['allocation_scope'] ?? '') === 'stable_range'
            ? (int)($lease['port'] ?? 0)
            : 0;
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
    ): EdgeRuntimeDecision {
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
            $decision->requestedMode === self::MODE_AUTO ? null : $exactPort,
        );
        $fallbackPort = (string)($lease['allocation_scope'] ?? '') === 'stable_range'
            ? (int)($lease['port'] ?? 0)
            : 0;
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

    /** @return array<string,mixed> */
    private function reservePublicPort(
        string $instanceName,
        string $bindHost,
        ?int $exactPort = null,
    ): array
    {
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
        $lease = $this->ports->reserveBound(
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
        $this->reservedListener = $this->ports->takeRetainedBoundSocket(
            (string)$lease['lease_id'],
        );
        if (!\is_resource($this->reservedListener)) {
            throw new \RuntimeException(
                'Reserved WLS public port did not retain its listening socket.'
            );
        }
        return $lease;
    }
}
