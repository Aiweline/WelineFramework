<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Controller\Backend\Address;
use WeShop\Address\Service\AddressService;
use Weline\Framework\Http\Request;

class AddressAjaxResponseTest extends TestCase
{
    public function testSaveReturnsJsonPayloadForAjaxRequest(): void
    {
        $addressService = $this->createMock(AddressService::class);
        $addressService->expects($this->once())
            ->method('saveAddress')
            ->willReturn(['address_id' => 12]);

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === true
                    && ($payload['message'] ?? '') === 'Address saved successfully.';
            }))
            ->willReturn('json-success');

        $request = $this->createMock(Request::class);
        $request->method('isPost')->willReturn(true);
        $request->method('isAjax')->willReturn(true);
        $request->method('getParams')->willReturn([
            'customer_id' => 1,
            'firstname' => 'A',
            'lastname' => 'B',
            'country' => 'CN',
        ]);

        self::setProtectedProperty($controller, 'request', $request);

        self::assertSame('json-success', $controller->save());
    }

    private static function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }
        if (!$reflection) {
            self::fail(sprintf('Property %s not found.', $property));
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}

