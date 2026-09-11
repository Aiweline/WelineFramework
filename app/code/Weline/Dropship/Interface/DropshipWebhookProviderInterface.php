<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

interface DropshipWebhookProviderInterface extends DropshipProviderInterface
{
    /**
     * Pure parse — no side effects / no remote calls.
     *
     * @param array<string, mixed> $headers
     * @param string $body
     * @return array{ok:bool,event?:string,external_id?:string,payload?:array<string,mixed>,message?:string}
     */
    public function parseWebhook(array $headers, string $body): array;
}
