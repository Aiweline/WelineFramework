<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Exact private ingress wire contract shared by Nginx, Dispatcher and Worker. */
final class GatewayBackendIngressProtocol
{
    public const AUTH_HEADER = 'X-WLS-Edge-Token';
    public const AUTH_HEADER_KEY = 'x-wls-edge-token';
    public const CLIENT_PROTOCOL_HEADER = 'X-WLS-Client-Protocol';
    public const CLIENT_PROTOCOL_HEADER_KEY = 'x-wls-client-protocol';

    private function __construct()
    {
    }
}
