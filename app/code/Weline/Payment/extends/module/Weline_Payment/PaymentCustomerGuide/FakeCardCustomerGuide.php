<?php

declare(strict_types=1);

namespace Weline\Payment\Extends\Module\Weline_Payment\PaymentCustomerGuide;

use Weline\Payment\Interface\PaymentCustomerGuideInterface;

final class FakeCardCustomerGuide implements PaymentCustomerGuideInterface
{
    public function getMethodCode(): string
    {
        return 'fake_card';
    }

    public function getProviderCode(): string
    {
        return 'fake';
    }

    public function getTitle(): string
    {
        return (string) __('本地测试支付');
    }

    public function getSummary(): string
    {
        return (string) __('了解本地测试支付的开发验证流程、适用场景与注意事项。');
    }

    public function getGuideTitle(): string
    {
        return (string) __('本地测试支付指南');
    }

    public function getPolicyTitle(): string
    {
        return (string) __('本地测试支付政策');
    }

    public function getAgreementTitle(): string
    {
        return (string) __('本地测试支付用户协议');
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
        return 1000;
    }
}
