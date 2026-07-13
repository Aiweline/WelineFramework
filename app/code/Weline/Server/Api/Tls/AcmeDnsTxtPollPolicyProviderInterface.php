<?php

declare(strict_types=1);

namespace Weline\Server\Api\Tls;

interface AcmeDnsTxtPollPolicyProviderInterface
{
    /**
     * @return array{
     *     max_seconds:int,
     *     interval_seconds:int,
     *     visible_use_public_doh:bool,
     *     max_seconds_gname?:int,
     *     max_seconds_cloudflare?:int
     * }
     */
    public function getPolicy(): array;
}
