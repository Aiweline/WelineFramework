<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentQueryCommand;
use Weline\Payment\Api\Data\PaymentResumeCommand;
use Weline\Payment\Api\Data\PaymentStartCommand;

/**
 * Versioned payment orchestration（REQ-011 / MOD-P2F-001）.
 * Callers MUST NOT pass amount, currency, merchant account, or Provider reference.
 * Snapshot is always resolved via PayableResolverRegistry.
 */
interface PaymentFacadeV2Interface
{
    public function start(PaymentStartCommand $command): PaymentOperationResult;

    public function resume(PaymentResumeCommand $command): PaymentOperationResult;

    public function query(PaymentQueryCommand $command): PaymentOperationResult;
}
