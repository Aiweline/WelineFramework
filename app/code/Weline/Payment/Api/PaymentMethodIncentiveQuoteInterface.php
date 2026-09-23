<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * 支付方式激励折扣报价 / 入快照 SPI（归属 Weline_Payment）。
 * 配置真相源 = SystemConfig payment/method/{code}/incentive_*；≠ supported_discount_actions。
 */
interface PaymentMethodIncentiveQuoteInterface
{
    public const SOURCE_TYPE = 'payment_method_incentive';

    /**
     * @param array<string, mixed> $runtimeConfig payment/method/{code}/* 扁平配置
     * @return array{
     *   available: bool,
     *   savings_minor: int,
     *   display: string,
     *   type?: string,
     *   percent?: float|int,
     *   funding_source?: string,
     *   rule_version?: string,
     *   line?: array<string, mixed>|null
     * }
     */
    public function quote(
        string $methodCode,
        array $runtimeConfig,
        int $baseAmountMinor,
        string $currencyCode,
        bool $methodAvailable = true,
    ): array;

    /**
     * 剔旧激励行 → 报价 → 写入 discount_lines，并下调 amount_minor / totals。
     *
     * @param array<string, mixed> $orderData
     * @param array<string, mixed> $runtimeConfig
     * @return array{order_data: array<string, mixed>, savings_minor: int, quote: array<string, mixed>}
     */
    public function applyToOrderData(string $methodCode, array $orderData, array $runtimeConfig): array;

    /**
     * 列表扁字段投影（contracts §payload）。
     *
     * @param array<string, mixed> $quote
     * @return array{
     *   incentive_savings_minor: int,
     *   incentive_display: string,
     *   incentive_available: bool,
     *   incentive_type?: string,
     *   incentive_percent?: float|int
     * }
     */
    public function toListPayloadFields(array $quote): array;
}
