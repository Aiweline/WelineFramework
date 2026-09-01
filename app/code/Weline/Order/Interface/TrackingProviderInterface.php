<?php

declare(strict_types=1);

namespace Weline\Order\Interface;

use Throwable;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackRequest;
use Weline\Order\Api\Data\Tracking\TrackingFeedbackResult;
use Weline\Order\Api\Data\Tracking\TrackingProviderError;
use Weline\Order\Api\Data\Tracking\TrackingQueryRequest;
use Weline\Order\Api\Data\Tracking\TrackingResult;
use Weline\Order\Api\Data\Tracking\TrackingTestConnectionRequest;

/**
 * 订单物流跟踪 Provider 契约（对齐万能支付 ProviderInterface 边界）。
 *
 * Provider 只返回结果或归一化反馈，不得直接改订单状态机 / 库存 / 支付。
 */
interface TrackingProviderInterface
{
    public function getCode(): string;

    public function getProviderCode(): string;

    public function getProviderApiVersion(): string;

    public function getWebhookSchemaVersion(): string;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * Required keys: icon_url|icon (non-empty), title.
     * Optional: description, track_template_code, config_template_code.
     *
     * @return array<string, mixed>
     */
    public function getDisplayMetadata(): array;

    /**
     * Canonical flow stages this provider can present (code/label/sort/icon).
     *
     * @return list<array<string, mixed>>
     */
    public function getFlowStages(): array;

    /**
     * @return array<string, mixed>
     */
    public function getConfigSchema(): array;

    public function queryTracking(TrackingQueryRequest $request): TrackingResult;

    /**
     * Pure: no DB/cache/queue/network side effects and no order state transitions.
     */
    public function verifyFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult;

    /**
     * Pure: parse already-verified feedback; MUST NOT advance order/shipment state.
     */
    public function parseFeedback(TrackingFeedbackRequest $request): TrackingFeedbackResult;

    public function testConnection(TrackingTestConnectionRequest $request): TrackingResult;

    /**
     * @param Throwable|array<string, mixed> $error
     */
    public function normalizeError(Throwable|array $error): TrackingProviderError;
}
