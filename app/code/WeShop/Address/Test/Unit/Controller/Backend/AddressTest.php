<?php

declare(strict_types=1);

namespace WeShop\Address\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\Address\Controller\Backend\Address;
use WeShop\Address\Service\AddressService;
use Weline\Framework\Http\Request\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Message\Manager as MessageManager;

class AddressTest extends TestCase
{
    private AddressService $addressService;
    private Address $controller;

    protected function setUp(): void
    {
        $this->addressService = $this->createMock(AddressService::class);
        $this->controller = new Address($this->addressService);
    }

    public function testIndexReturnsAddressManagementPage(): void
    {
        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'fetchBase'])
            ->getMock();

        $controller->expects($this->exactly(4))->method('assign')->willReturnCallback(
            function (string $key, mixed $value) use (&$assignments) {
                $assignments[$key] = $value;
                return $this;
            }
        );
        $controller->expects($this->once())->method('fetch')->willReturn('page_html');

        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $result = $controller->index();

        $this->assertSame('page_html', $result);
        $this->assertArrayHasKey('page_title', $assignments);
        $this->assertArrayHasKey('add_url', $assignments);
        $this->assertArrayHasKey('edit_url', $assignments);
        $this->assertArrayHasKey('delete_url', $assignments);
    }

    public function testEditWithInvalidIdAddsErrorMessage(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->isType('string'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => -1,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $controller->expects($this->once())->method('redirect');

        $controller->edit();
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
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->never())->method('fetch');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 999,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $controller->expects($this->once())->method('redirect');

        $controller->edit();
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
            ->onlyMethods(['assign', 'fetch', 'getMessageManager', 'redirect', 'fetchBase'])
            ->getMock();

        $controller->expects($this->exactly(5))->method('assign')->willReturnCallback(
            function (string $key, mixed $value) use (&$assignments) {
                $assignments[$key] = $value;
                return $this;
            }
        );
        $controller->expects($this->once())->method('fetch')->willReturn('edit_page_html');

        $this->setProtectedProperty($controller, '_request', new class {
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_url', new class {
            public function getBackendUrl(string $path): string
            {
                return '/backend/' . ltrim($path, '*/');
            }
        });

        $result = $controller->edit();

        $this->assertSame('edit_page_html', $result);
        $this->assertSame(5, $assignments['address_id']);
        $this->assertSame($addressData, $assignments['address']);
        $this->assertSame('Edit Address', $assignments['page_title']);
    }

    public function testDeleteWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid request method.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return false; }
            public function isDelete(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed { return $default; }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->delete();
    }

    public function testDeleteWithInvalidAddressIdAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid address ID.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function isDelete(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 0,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->delete();
    }

    public function testDeleteWithInvalidCustomerIdAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid customer ID.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function isDelete(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'customer_id' => 0,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->delete();
    }

    public function testDeleteSuccessfullyDeletesAddress(): void
    {
        $this->addressService->expects($this->once())
            ->method('deleteAddress')
            ->with(5, 12)
            ->willReturn(true);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('Address deleted successfully.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function isDelete(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->delete();
    }

    public function testDeleteHandlesServiceException(): void
    {
        $this->addressService->expects($this->once())
            ->method('deleteAddress')
            ->willThrowException(new \RuntimeException('Database error'));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Database error'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function isDelete(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->delete();
    }

    public function testSetDefaultWithInvalidRequestMethodAddsError(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid request method.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return false; }
            public function getParam(string $key, mixed $default = null): mixed { return $default; }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->setDefault();
    }

    public function testSetDefaultSuccessfullyUpdatesDefaultAddress(): void
    {
        $this->addressService->expects($this->once())
            ->method('setDefaultAddress')
            ->with(5, 12);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with('Default address updated successfully.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->setDefault();
    }

    public function testSetDefaultHandlesInvalidAddressId(): void
    {
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Invalid address ID.');

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 0,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->setDefault();
    }

    public function testSetDefaultHandlesServiceException(): void
    {
        $this->addressService->expects($this->once())
            ->method('setDefaultAddress')
            ->willThrowException(new \RuntimeException('Service unavailable'));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('Service unavailable'));

        $controller = $this->getMockBuilder(Address::class)
            ->setConstructorArgs([$this->addressService])
            ->onlyMethods(['getMessageManager', 'redirect'])
            ->getMock();

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function getParam(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'id' => 5,
                    'customer_id' => 12,
                    default => $default
                };
            }
        });
        $this->setProtectedProperty($controller, '_messageManager', $messageManager);

        $controller->expects($this->once())->method('redirect');

        $controller->setDefault();
    }

    public function testSaveReturnsJsonWhenAjaxRequest(): void
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
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? null) === true
                    && ($payload['message'] ?? '') === 'Address saved successfully.';
            }))
            ->willReturn('json-ok');

        $this->setProtectedProperty($controller, '_request', new class {
            public function isPost(): bool { return true; }
            public function isAjax(): bool { return true; }
            public function getParams(): array
            {
                return [
                    'customer_id' => 12,
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'telephone' => '123456',
                    'country' => 'CN',
                    'province' => 'Beijing',
                    'city' => 'Beijing',
                    'street' => 'Road 1',
                ];
            }
        });

        $this->assertSame('json-ok', $controller->save());
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
