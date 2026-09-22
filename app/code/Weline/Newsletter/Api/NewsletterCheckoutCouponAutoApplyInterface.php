<?php

declare(strict_types=1);

namespace Weline\Newsletter\Api;

/**
 * T2：结账侧在邮箱可识别后调用（可选；主路径也可经 Observer 挂 Checkout 事件）。
 * Newsletter 禁止读 Checkout Model；调用方传入已解析的 email / customer_id / cart_type。
 */
interface NewsletterCheckoutCouponAutoApplyInterface
{
    /**
     * @param array{
     *   email?:string,
     *   customer_id?:int,
     *   website_id?:int,
     *   cart_type?:string,
     *   selling_mode?:string
     * } $context
     * @return array{applied:bool,coupon_code:string,reason:string}
     */
    public function applyForRecognizedEmail(array $context): array;
}
