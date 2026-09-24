<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Service\PaymentMethodIconResolver;

final class PaymentMethodIconResolverTest extends TestCase
{
    public function testProviderStaticRefResolvesToModuleStaticsUrl(): void
    {
        $resolver = new PaymentMethodIconResolver();
        $display = $resolver->apply([
            'icon_url' => 'Weline_Payment::img/payment/fake-card.svg',
            'title' => 'Fake',
        ]);

        $url = (string) ($display['icon_url'] ?? '');
        self::assertNotSame('', $url);
        self::assertStringStartsWith('/static/', $url);
        self::assertDoesNotMatchRegularExpression('#^/Weline/Payment/view/statics/#', $url);
        self::assertStringContainsString('fake-card.svg', $url);
        self::assertSame('provider', $display['icon_source']);
    }

    public function testConfigOverrideBeatsProviderDefault(): void
    {
        $resolver = new PaymentMethodIconResolver();
        $display = $resolver->apply(
            ['icon_url' => 'Weline_Payment::img/payment/fake-card.svg'],
            ['icon' => 'payment/icons/custom.png']
        );

        self::assertSame('/media/image/payment/icons/custom.png', $display['icon_url']);
        self::assertSame('config', $display['icon_source']);
    }

    public function testBuiltinProvidersPublishIconsAndAssetsExist(): void
    {
        $resolver = new PaymentMethodIconResolver();
        $fake = (new FakeProvider())->getDisplayMetadata();
        $paypal = (new PayPalProvider())->getDisplayMetadata();

        self::assertNotSame('', $resolver->extractProviderIcon($fake));
        self::assertNotSame('', $resolver->extractProviderIcon($paypal));
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/img/payment/fake-card.svg');
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/img/payment/paypal.svg');
    }
}
