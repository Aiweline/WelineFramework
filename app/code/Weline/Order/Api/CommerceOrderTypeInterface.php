<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * 售卖类型 SPI（Order 宿主）。稳定 code 在 extends 发现期注册；内置 toc，业务模块可挂 tob。
 * Order 永不 requires B2B：未注册的 code 在热路径不存在。
 */
interface CommerceOrderTypeInterface
{
    public function getCode(): string;

    /** 后台/店面徽章文案（可被 __()/lang 翻译的源文案）。 */
    public function getLabel(): string;

    /** Theme badge tone hint：primary|info|success|warning|muted */
    public function getBadgeTone(): string;

    public function requiresCustomerLogin(): bool;

    /** tob 等：单位价是否禁止零售折扣栈 */
    public function disablesStorefrontDiscounts(): bool;
}
