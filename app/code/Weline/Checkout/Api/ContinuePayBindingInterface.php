<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

/**
 * 继续支付绑定（支付域能力，不是 CheckoutType）。
 * 同一浏览器会话最多一个续付桶；再次 adopt 替换旧绑定。
 */
interface ContinuePayBindingInterface
{
    public const MODE_NORMAL = 'normal';
    public const MODE_CONTINUE_PAY = 'continue_pay';

    /**
     * @param array<string, mixed> $params quote_token, order_uuid, idempotency_key?, payment_method?, …
     * @return array<string, mixed> bucket + order snapshot + type
     */
    public function adopt(array $params, ?int $customerId): array;

    /**
     * @return array{ok:bool,message?:string}
     */
    public function release(?string $bucketId = null): array;
}
