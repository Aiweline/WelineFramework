<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 更换地址 picking 须能请求全量地址簿（跨国家），默认 getContext 仍按当前配送国过滤。
 */
final class CheckoutDeliveryContextPickerAllAddressesContractTest extends TestCase
{
    public function testGetContextSupportsListAllAddressesFlag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php'
        );
        $provider = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );

        self::assertStringContainsString('wantsAllAddresses', $src);
        self::assertStringContainsString("listAddresses(\$listAll ? '' : \$countryCode)", $src);
        self::assertStringContainsString("'list_all_addresses'", $src);
        self::assertStringContainsString("'list_all_addresses' => ['type' => 'boolean'", $provider);
        self::assertStringContainsString("'for_picker' => ['type' => 'boolean'", $provider);
    }
}
