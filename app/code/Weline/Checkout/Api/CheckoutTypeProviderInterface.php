<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

/**
 * 结账 Type SPI（Checkout 宿主）。业务模块 extends 注册；禁止把「继续支付」注册为 Type。
 * 热路径 cart_type：standard↔toc，tob↔tob。
 */
interface CheckoutTypeProviderInterface
{
    public function getCode(): string;

    /** 店面切换列表文案（可被 __() 翻译的源文案）。 */
    public function getLabel(): string;

    public function getSortOrder(): int;

    /**
     * @param array<string, mixed> $context website_id, customer_id, …
     */
    public function isAvailable(array $context = []): bool;

    /** 映射既有 cart_type / selling_mode（toc|tob|…）。 */
    public function getCartTypeCode(): string;

    /**
     * 流程能力声明（壳按声明显隐；本迭代可只读）。
     *
     * @return array{
     *   needs_shipping_address?: bool,
     *   needs_shipping_method?: bool,
     *   label_hint?: string
     * }
     */
    public function getCapabilities(): array;
}
