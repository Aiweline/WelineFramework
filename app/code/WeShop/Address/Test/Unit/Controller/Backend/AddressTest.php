<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Controller\Backend\Address;
use WeShop\Address\Service\AddressService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

final class AddressTest extends TestCase
{
    private AddressService $addressService;

    protected function setUp(): void
    {
        $this->addressService = $this->createMock(AddressService::class);
    }

    public function testIndexReturnsAddressManagementPage(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(6))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Address::templates/Backend/Address/Index/index.phtml')
            ->willReturn('address-index-page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'customer_id' => 12,
        ]));
        $this->setControllerUrl($controller);

        self::assertSame('address-index-page', $controller->index());
        self::assertSame('Address Management', $assignments['page_title'] ?? null);
        self::assertSame('/backend/backend/address/edit', $assignments['add_url'] ?? null);
        self::assertSame('/backend/backend/address/edit', $assignments['edit_url'] ?? null);
        self::assertSame('/backend/backend/address/delete', $assignments['delete_url'] ?? null);
        self::assertSame('/backend/backend/address/set-default', $assignments['set_default_url'] ?? null);
        self::assertSame(12, $assignments['customer_id'] ?? null);
    }

    public function testEditWithoutPositiveIdReturnsCreatePage(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Address::templates/Backend/Address/Edit/index.phtml')
            ->willReturn('address-edit-page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => -1,
        ]));
        $this->setControllerUrl($controller);

        self::assertSame('address-edit-page', $controller->edit());
        self::assertSame('Add Address', $assignments['page_title'] ?? null);
        self::assertSame(-1, $assignments['address_id'] ?? null);
        self::assertNull($assignments['address'] ?? null);
        self::assertSame('/backend/backend/address/save', $assignments['save_url'] ?? null);
    }

    public function testEditWithNonExistentAddressAddsErrorMessage(): void
    {
        $this->addressService->expects($this->once())
            ->method('getAddress')
            ->with(999)
            ->willReturn(null);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Address not found.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();
        $controller->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('*/backend/address');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 999,
        ]));

        self::assertSame('', $controller->edit());
    }

    public function testEditWithValidAddressReturnsEditPage(): void
    {
        $addressData = [
            'address_id' => 5,
            'customer_id' => 12,
            'contact_name' => 'John Doe',
            'telephone' => '1234567890',
            'country' => 'CN',
            'region' => 'Beijing',
            'city' => 'Beijing',
            'street' => 'Main Street 123',
            'is_default' => true,
        ];

        $this->addressService->expects($this->once())
            ->method('getAddress')
            ->with(5)
            ->willReturn($addressData);

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Address::templates/Backend/Address/Edit/index.phtml')
            ->willReturn('address-edit-page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
        ]));
        $this->setControllerUrl($controller);

        self::assertSame('address-edit-page', $controller->edit());
        self::assertSame('Edit Address', $assignments['page_title'] ?? null);
        self::assertSame(5, $assignments['address_id'] ?? null);
        self::assertSame($addressData, $assignments['address'] ?? null);
        self::assertSame('/backend/backend/address/save', $assignments['save_url'] ?? null);
    }

    public function testSaveRejectsInvalidRequestMethodViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => false,
                'message' => 'Invalid request method.',
            ])
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([], false, false, true));

        self::assertSame('json-error', $controller->save());
    }

    public function testSaveReturnsSuccessJsonWhenAddressCreated(): void
    {
        $this->addressService->expects($this->once())
            ->method('saveAddress')
            ->with($this->isType('array'))
            ->willReturn(['address_id' => 88]);

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => true,
                'message' => 'Address saved successfully.',
            ])
            ->willReturn('json-ok');

        $this->setControllerRequest($controller, $this->createRequestMock([], true, false, true, [
            'customer_id' => 12,
            'firstname' => 'John',
        ]));

        self::assertSame('json-ok', $controller->save());
    }

    public function testSaveHandlesServiceExceptionViaAjax(): void
    {
        $this->addressService->expects($this->once())
            ->method('saveAddress')
            ->willThrowException(new \RuntimeException('Database error'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Database error');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([], true, false, true, [
            'customer_id' => 12,
        ]));

        self::assertSame('json-error', $controller->save());
    }

    public function testDeleteRejectsInvalidRequestMethodViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => false,
                'message' => 'Invalid request method.',
            ])
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([], false, false, true));

        self::assertSame('json-error', $controller->delete());
    }

    public function testDeleteRejectsInvalidAddressIdViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Invalid address ID.');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 0,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-error', $controller->delete());
    }

    public function testDeleteRejectsInvalidCustomerIdViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Invalid customer ID.');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'customer_id' => 0,
        ], true, false, true));

        self::assertSame('json-error', $controller->delete());
    }

    public function testDeleteReturnsSuccessJsonViaAjax(): void
    {
        $this->addressService->expects($this->once())
            ->method('deleteAddress')
            ->with(5, 12)
            ->willReturn(true);

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => true,
                'message' => 'Address deleted successfully.',
            ])
            ->willReturn('json-ok');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-ok', $controller->delete());
    }

    public function testDeleteHandlesServiceExceptionViaAjax(): void
    {
        $this->addressService->expects($this->once())
            ->method('deleteAddress')
            ->willThrowException(new \RuntimeException('Database error'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Database error');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-error', $controller->delete());
    }

    public function testSetDefaultRejectsInvalidRequestMethodViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => false,
                'message' => 'Invalid request method.',
            ])
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([], false, false, true));

        self::assertSame('json-error', $controller->setDefault());
    }

    public function testSetDefaultReturnsSuccessJsonViaAjax(): void
    {
        $this->addressService->expects($this->once())
            ->method('setDefaultAddress')
            ->with(5, 12);

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with([
                'success' => true,
                'message' => 'Default address updated successfully.',
            ])
            ->willReturn('json-ok');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-ok', $controller->setDefault());
    }

    public function testSetDefaultHandlesInvalidAddressIdViaAjax(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Invalid address ID.');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 0,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-error', $controller->setDefault());
    }

    public function testSetDefaultHandlesServiceExceptionViaAjax(): void
    {
        $this->addressService->expects($this->once())
            ->method('setDefaultAddress')
            ->willThrowException(new \RuntimeException('Service unavailable'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === false
                    && str_contains((string) ($payload['message'] ?? ''), 'Service unavailable');
            }))
            ->willReturn('json-error');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'id' => 5,
            'customer_id' => 12,
        ], true, false, true));

        self::assertSame('json-error', $controller->setDefault());
    }

    private function createRequestMock(
        array $params = [],
        bool $isPost = true,
        bool $isDelete = false,
        bool $isAjax = false,
        array $allParams = []
    ): Request {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam', 'isPost', 'isDelete', 'isAjax', 'getParams'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
        $request->method('isPost')->willReturn($isPost);
        $request->method('isDelete')->willReturn($isDelete);
        $request->method('isAjax')->willReturn($isAjax);
        $request->method('getParams')->willReturn($allParams);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->willReturnCallback(static fn (string $path): string => '/backend/' . ltrim($path, '*/'));

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
