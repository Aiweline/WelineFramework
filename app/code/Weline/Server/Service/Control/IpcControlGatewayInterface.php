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
}
