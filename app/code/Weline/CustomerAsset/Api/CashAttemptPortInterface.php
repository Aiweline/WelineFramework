<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Api;

/**
 * 现金 Attempt 端口（不碰 Payment 内部）。
 */
interface CashAttemptPortInterface
{
    /**
     * @param array{payable_id:string,amount_minor:int,event_id:string} $request
     * @return array{ok:bool,attempt_id:?string,status:string,error?:string,idempotent?:bool}
     */
    public function attempt(array $request): array;

    /**
     * @param array{attempt_id:string,amount_minor:int,event_id:string} $request
     * @return array{ok:bool,refund_id:?string,status:string,error?:string,idempotent?:bool}
     */
    public function refund(array $request): array;

    public function attemptCount(): int;

    public function refundCount(): int;
}
