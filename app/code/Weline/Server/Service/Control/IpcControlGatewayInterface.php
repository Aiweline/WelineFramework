<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

interface IpcControlGatewayInterface
{
    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function command(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 6.0,
        ?float $deadlineMonotonic = null,
    ): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function reloadAsync(
        string $instanceName,
        string $reloadType,
        float $timeout = 5.0
    ): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function cacheClear(string $instanceName, float $timeout = 5.0): array;

    /** @return array{success:bool,message:string,data:array} */
    public function cacheNamespaceInvalidateV1(
        string $instanceName,
        int $authorityClock,
        array $changes,
        string $requestId = '',
        float $timeout = 0.1,
        ?string $operationId = null,
    ): array;

    /** @return array{success:bool,message:string,data:array} */
    public function cacheNamespaceInvalidationStatusV1(
        string $instanceName,
        string $operationId,
        float $timeout = 0.1,
    ): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function setMaintenanceMode(string $instanceName, bool $enabled, float $timeout = 6.0): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array;

    /**
     * 通知 WLS 热重载 SSL 证书映射。
     *
     * @param string   $instanceName WLS 实例名
     * @param string[] $domains      需要刷新的域名列表；空数组表示全量刷新
     * @return array{success:bool,message:string,data:array}
     */
    public function reloadSslCert(string $instanceName = 'default', array $domains = []): array;

    /**
     * Reload one exact immutable serving manifest and wait for the terminal
     * per-process acknowledgement aggregate.
     *
     * @param string[] $domains
     * @return array{success:bool,message:string,data:array}
     */
    public function reloadSslCertAndWait(
        array $domains,
        string $instanceName,
        string $operationId,
        int $expectedManifestGeneration,
        string $expectedManifestDigest,
        int $expectedTlsRouteCount,
        float $timeout = 8.0,
    ): array;

    /**
     * Fail-stop every TLS/H3 participant owned by the exact persisted Master
     * fence. This containment path deliberately does not depend on a newly
     * published serving manifest.
     *
     * @return array{success:bool,message:string,data:array}
     */
    public function quarantineSslServingAndWait(
        string $instanceName,
        string $operationId,
        string $reason,
        float $timeoutSeconds = 8.0,
    ): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function securityUnblock(string $instanceName = 'default', ?string $ip = null, bool $clearAll = false): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function scaleWorkers(string $instanceName, int $targetWorkers, float $timeout = 10.0): array;

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function scalingStatus(string $instanceName, float $timeout = 4.0): array;
}
