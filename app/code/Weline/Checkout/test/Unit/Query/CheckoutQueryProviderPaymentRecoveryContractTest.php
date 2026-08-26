<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutQueryProviderPaymentRecoveryContractTest extends TestCase
{
    public function testProviderUsesPublishedCartScopeContractAndPublishesRecoveryOperation(): void
    {
        $provider = $this->read('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');

        self::assertStringContainsString('use Weline\\Cart\\Api\\CartScopeResolverInterface;', $provider);
        self::assertStringNotContainsString('use Weline\\Cart\\Service\\CartScopeResolver;', $provider);
        self::assertStringContainsString("'resumePaymentV2' => \$this->resumePaymentV2(\$params)", $provider);
        self::assertStringContainsString("'outcome' => 'failed'", $provider);
        self::assertStringContainsString("'checkout_group_uuid' => \$result->checkoutGroupUuid", $provider);
        self::assertStringContainsString("'order_uuids' => \$result->orderUuids", $provider);
        self::assertStringContainsString("'checkout_token' => \$quoteToken", $provider);
        self::assertStringContainsString("'name' => 'resumePaymentV2'", $provider);
        self::assertStringContainsString('->beginRetry(', $provider);
    }

    public function testProviderPublishesAuthenticatedCustomerDefaultDeliveryAddress(): void
    {
        $provider = $this->read('app/code/Weline/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');

        self::assertStringContainsString('use Weline\\Shipping\\Model\\DeliveryAddress;', $provider);
        self::assertStringContainsString('use Weline\\Shipping\\Service\\DeliveryAddressService;', $provider);
        self::assertStringContainsString('use Weline\\Checkout\\Service\\CheckoutDeliveryContextService;', $provider);
        self::assertStringContainsString("'default_shipping_address' => \$this->customerAddressPrefill()", $provider);
        self::assertStringContainsString("'delivery' => \$this->deliveryContextService->getContext(\$params)", $provider);
        self::assertStringContainsString("'getDeliveryContext' => \$this->deliveryContext(\$params)", $provider);
        self::assertStringContainsString('private function customerAddressPrefill(): array', $provider);
        self::assertStringContainsString('->getDefaultByCustomer($identity->getId())', $provider);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 7) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
