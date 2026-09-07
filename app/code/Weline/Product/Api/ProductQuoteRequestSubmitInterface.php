<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Product-owned storefront quote submission (quote_only commerce path).
 */
interface ProductQuoteRequestSubmitInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array{accepted:bool,duplicate:bool,quote_request_id:int,message:string}
     */
    public function submit(array $payload): array;
}
