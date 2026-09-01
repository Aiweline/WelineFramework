<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Payment\PaymentCustomerGuide;

use Weline\Payment\Interface\PaymentCustomerGuideInterface;

final class PayPalCustomerGuide implements PaymentCustomerGuideInterface
{
    public function getMethodCode(): string
    {
        return 'paypal';
    }

    public function getProviderCode(): string
    {
        return 'paypal';
    }

    public function getTitle(): string
    {
        return (string) __('PayPal');
    }

    public function getSummary(): string
    {
        return (string) __('了解如何使用 PayPal 账户或银行卡完成支付，以及退款与争议处理规则。');
    }

    public function getGuideTitle(): string
    {
        return (string) __('PayPal 支付指南');
    }

    public function getPolicyTitle(): string
    {
        return (string) __('PayPal 支付政策');
    }

    public function getAgreementTitle(): string
    {
        return (string) __('PayPal 用户协议');
    }

    public function getGuideTemplateCode(): string
    {
        return 'guide';
    }

    public function getPolicyTemplateCode(): string
    {
        return 'policy';
    }

    public function getAgreementTemplateCode(): string
    {
        return 'agreement';
    }

    public function getGuideLayoutType(): string
    {
        return 'payment_guide';
    }

    public function getPolicyLayoutType(): string
    {
        return 'payment_guide';
    }

    public function getAgreementLayoutType(): string
    {
        return 'payment_guide';
    }

    public function getSortOrder(): int
    {
        return 20;
    }
}
