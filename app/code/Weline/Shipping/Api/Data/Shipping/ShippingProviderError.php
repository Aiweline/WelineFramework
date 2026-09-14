<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingProviderError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly bool $retryable = false,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array{code:string,message:string,retryable:bool,context:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'context' => $this->context,
        ];
    }
}
