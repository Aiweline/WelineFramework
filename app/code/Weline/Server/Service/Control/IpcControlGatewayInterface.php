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
        float $timeout = 6.0
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
}
