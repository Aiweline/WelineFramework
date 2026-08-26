<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/** Stable command response for backend HTTP/Resource mapping. */
final readonly class ProductAdminResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $success,
        public ?string $errorCode,
        public string $message,
        public array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data = [], string $message = ''): self
    {
        return new self(true, null, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function fail(string $errorCode, string $message, array $data = []): self
    {
        return new self(false, $errorCode, $message, $data);
    }

    /** @return array{success:bool,error_code:?string,message:string,data:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
