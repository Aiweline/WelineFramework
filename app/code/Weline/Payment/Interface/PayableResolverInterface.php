<?php

declare(strict_types=1);

namespace Weline\Payment\Interface;

use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableContext;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\RefundRequest;
use Weline\Payment\Api\Data\RefundResult;
use Weline\Payment\Model\PaymentIntent;

interface PayableResolverInterface
{
    public function getPayableType(): string;

    public function resolve(string $payableId, ?Actor $actor = null): PayableContext;

    public function snapshot(PayableContext $context): PayableSnapshot;

    public function canPay(PayableSnapshot $snapshot, Actor $actor): bool;

    public function canCancel(PayableSnapshot $snapshot): bool;

    public function canRefund(RefundRequest $request): bool;

    public function onPaid(PaymentIntent $intent): void;

    public function onPartiallyPaid(PaymentIntent $intent): void;

    public function onRefunded(RefundResult $result): void;

    public function onExpired(PaymentIntent $intent): void;

    public function onRiskReview(PaymentIntent $intent): void;

    public function releaseResources(PaymentIntent $intent, string $reason): void;

    /**
     * @return array<string, mixed>
     */
    public function getPayerPolicy(PayableSnapshot $snapshot): array;

    /**
     * @return string[]
     */
    public function getBusinessTags(PayableSnapshot $snapshot): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLineItems(PayableSnapshot $snapshot): array;
}
