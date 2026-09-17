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
    /**
     * @param (callable(string):string)|null $urlBuilder
     */
    private function registry(?callable $urlBuilder = null): PaymentCustomerGuideRegistry
    {
        $builder = $urlBuilder ?? static fn (string $route): string => 'https://url.test/' . $route;

        return new PaymentCustomerGuideRegistry(null, $builder);
    }

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
        $registry = $this->registry();

        self::assertSame('guide/payment', $registry->buildGuideRoute(''));
        self::assertSame('guide/payment/paypal', $registry->buildGuideRoute('paypal'));
        self::assertSame('guide/payment/paypal/policy', $registry->buildPolicyRoute('paypal'));
        self::assertSame('guide/payment/paypal/agreement', $registry->buildAgreementRoute('paypal'));
        self::assertSame('https://url.test/guide/payment', $registry->buildGuideUrl(''));
        self::assertSame('https://url.test/guide/payment/paypal', $registry->buildGuideUrl('paypal'));
        self::assertSame('https://url.test/guide/payment/paypal/policy', $registry->buildPolicyUrl('paypal'));
        self::assertSame('https://url.test/guide/payment/paypal/agreement', $registry->buildAgreementUrl('paypal'));
    }

    public function testBuiltInGuideTemplateReferencesMatchModuleConvention(): void
    {
        $registry = $this->registry();
        $paypalGuide = new PayPalCustomerGuide();

        $expectedGuideTemplate = 'Weline_Payment::templates/Frontend/guide/payment/'
            . $paypalGuide->getMethodCode() . '/'
            . $paypalGuide->getGuideTemplateCode() . '.phtml';

        self::assertSame('https://url.test/guide/payment/paypal', $registry->buildGuideUrl($paypalGuide->getMethodCode()));
        self::assertStringContainsString('guide/payment/paypal/guide.phtml', $expectedGuideTemplate);
    }

    public function testGuideUrlsGoThroughFrameworkUrlHelper(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentCustomerGuideRegistry.php');
        self::assertStringContainsString('getUrl($route)', $src);
        self::assertStringNotContainsString("'/' . \$this->buildGuideRoute", $src);
        self::assertStringNotContainsString("'/' . \$this->buildPolicyRoute", $src);
        self::assertStringNotContainsString("'/' . \$this->buildAgreementRoute", $src);
    }
}
