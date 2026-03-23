<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Controller\Frontend\Address;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Controller\Frontend\Address\Save;
use WeShop\Address\Service\AddressService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class SaveTest extends TestCase
{
    public function testIndexReturnsLoginRedirectPayloadForGuestCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->never())->method('saveAddress');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('https://example.com/customer/account/login');

        $controller = $this->getMockBuilder(Save::class)
            ->setConstructorArgs([$customerContext, $addressService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['data']['redirect_url'] ?? null) === 'https://example.com/customer/account/login';
            }))
            ->willReturn('json');

        $this->assertSame('json', $controller->index());
    }

    public function testIndexSavesAddressForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(18);

        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->once())
            ->method('saveAddress')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['customer_id'] === 18
                    && $payload['firstname'] === 'Ada'
                    && $payload['lastname'] === 'Lovelace'
                    && $payload['telephone'] === '+44 20 0000 0000'
                    && $payload['country_id'] === 'GB'
                    && $payload['region'] === 'London'
                    && $payload['city'] === 'London'
                    && $payload['street'] === '10 Downing Street'
                    && $payload['postcode'] === 'SW1A 2AA'
                    && $payload['is_default'] === true;
            }))
            ->willReturn([
                'address_id' => 9,
                'country_id' => 'GB',
            ]);

        $url = $this->createMock(Url::class);
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['address_id', null, 9],
            ['firstname', null, 'Ada'],
            ['lastname', null, 'Lovelace'],
            ['telephone', null, '+44 20 0000 0000'],
            ['phone', null, null],
            ['country_id', null, 'GB'],
            ['country', null, null],
            ['region', null, 'London'],
            ['city', null, 'London'],
            ['district', null, 'Westminster'],
            ['street', null, '10 Downing Street'],
            ['postcode', null, 'SW1A 2AA'],
            ['is_default', null, true],
        ]);

        $controller = $this->getMockBuilder(Save::class)
            ->setConstructorArgs([$customerContext, $addressService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (int) ($payload['data']['address_id'] ?? 0) === 9;
            }))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
