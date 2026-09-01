<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentCustomerGuide\FakeCardCustomerGuide;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentCustomerGuide\PayPalCustomerGuide;
use Weline\Payment\Interface\PaymentCustomerGuideInterface;
use Weline\Payment\Service\PaymentCustomerGuideRegistry;

final class PaymentCustomerGuideRegistryTest extends TestCase
{
    public function testBuiltInGuidesImplementCustomerGuideContract(): void
    {
        $guides = [
            new FakeCardCustomerGuide(),
            new PayPalCustomerGuide(),
        ];

        foreach ($guides as $guide) {
            self::assertInstanceOf(PaymentCustomerGuideInterface::class, $guide);
            self::assertNotSame('', $guide->getMethodCode());
            self::assertNotSame('', $guide->getGuideTemplateCode());
            self::assertNotSame('', $guide->getPolicyTemplateCode());
            self::assertNotSame('', $guide->getAgreementTemplateCode());
        }
    }

    public function testRegistryBuildsStablePublicUrls(): void
    {
        $registry = new PaymentCustomerGuideRegistry();

        self::assertSame('guide/payment', $registry->buildGuideRoute(''));
        self::assertSame('guide/payment/paypal', $registry->buildGuideRoute('paypal'));
        self::assertSame('guide/payment/paypal/policy', $registry->buildPolicyRoute('paypal'));
        self::assertSame('guide/payment/paypal/agreement', $registry->buildAgreementRoute('paypal'));
        self::assertSame('/guide/payment', $registry->buildGuideUrl(''));
        self::assertSame('/guide/payment/paypal', $registry->buildGuideUrl('paypal'));
        self::assertSame('/guide/payment/paypal/policy', $registry->buildPolicyUrl('paypal'));
        self::assertSame('/guide/payment/paypal/agreement', $registry->buildAgreementUrl('paypal'));
    }

    public function testBuiltInGuideTemplateReferencesMatchModuleConvention(): void
    {
        $registry = new PaymentCustomerGuideRegistry();
        $paypalGuide = new PayPalCustomerGuide();

        $expectedGuideTemplate = 'Weline_Payment::templates/Frontend/guide/payment/'
            . $paypalGuide->getMethodCode() . '/'
            . $paypalGuide->getGuideTemplateCode() . '.phtml';

        self::assertSame('/guide/payment/paypal', $registry->buildGuideUrl($paypalGuide->getMethodCode()));
        self::assertStringContainsString('guide/payment/paypal/guide.phtml', $expectedGuideTemplate);
    }
}
