<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Address;

class AddressTaglibRuntimeCallbackTest extends TestCore
{
    public function testRuntimeCallbackEmitsAddressMarkupWithoutPhpSource(): void
    {
        $runtime = Address::runtimeCallback();
        $html = $runtime(
            $this->createMock(Template::class),
            'tag-self-close',
            [
                'levels' => 'province,city,district',
                'code' => 'checkout-delivery-quick-add',
                'country' => 'CN',
                'country-name' => 'country',
                'province-name' => 'province',
                'city-name' => 'city',
                'district-name' => 'district',
                'searchable' => 'true',
                'cascade' => 'true',
                'district' => 'true',
                // Absolute url avoids unit-test DB via w_url().
                'url' => 'https://example.test/shipping/frontend/region/list',
            ],
            ''
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('data-w-address', $html);
        $this->assertStringContainsString('checkout-delivery-quick-add', $html);
        $this->assertStringContainsString('province|city|district', $html);
        $this->assertStringNotContainsString('<?php', $html);
        $this->assertStringNotContainsString('Weline_Taglib_resolve', $html);
        $this->assertStringNotContainsString('$Taglib__', $html);
    }

    public function testCompileCallbackStillPrefacesAttributeResolverPhp(): void
    {
        $callback = Address::callback();
        $html = $callback(
            'tag-self-close',
            [],
            [''],
            [
                'levels' => 'province,city,district',
                'code' => 'shipping',
                'searchable' => 'true',
                'url' => 'https://example.test/shipping/frontend/region/list',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('<?php', $html);
        $this->assertStringContainsString('data-w-address', $html);
        $this->assertStringContainsString('Weline_Taglib_resolve', $html);
    }
}
