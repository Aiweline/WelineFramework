<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Event;

interface AsyncEventTransportInterface
{
    public function name(): string;

    /** @return array{handle:string,metadata:array<string,mixed>,created:bool} */
    public function provision(int $deliveryId, int $attemptNo, string $idempotencyKey, array $content): array;

    /** @return array{accepted:bool,operation_id:string,error_code:string} */
    public function dispatch(string $handle): array;

    /** @return array{confirmed:bool,retryable:bool,error_code:string} */
    public function terminate(string $handle, string $fenceToken): array;
}
