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
    public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array;

    /**
     * 通知 WLS 热重载 SSL 证书映射。
     *
     * 当 SSL 证书被申请或更新后调用此方法，WLS 会：
     * 1. 清除指定域名（或全部）的 DB 回退负缓存；
     * 2. 清除这些域名在内存中的正缓存；
     * 3. 重新加载 ssl_certificate_map.json。
     *
     * @param string   $instanceName WLS 实例名
     * @param string[] $domains      需要刷新的域名列表；空数组 = 全量重载（不针对特定域名）
     * @return array{success:bool,message:string,data:array}
     */
    public function reloadSslCert(string $instanceName = 'default', array $domains = []): array;
}
