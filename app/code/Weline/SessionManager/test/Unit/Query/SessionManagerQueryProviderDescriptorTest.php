<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;
use Weline\SessionManager\Api\DeviceMetadataProviderInterface;
use Weline\SessionManager\Api\Persistence\DeviceRepositoryInterface;
use Weline\SessionManager\Controller\Backend\Device;
use Weline\SessionManager\Extends\Module\Weline_Framework\Query\SessionManagerQueryProvider;
use Weline\SessionManager\Service\AuthenticatedDeviceRegistry;

final class SessionManagerQueryProviderDescriptorTest extends TestCase
{
    public function testBackendMenuControllerAndQueryUseTheSameDedicatedAcl(): void
    {
        $source = 'Weline_SessionManager::device_manage_self';
        $controllerAttributes = (new \ReflectionClass(Device::class))->getAttributes(Acl::class);
        self::assertCount(1, $controllerAttributes);
        self::assertSame($source, $controllerAttributes[0]->newInstance()->getSourceId());

        $menu = simplexml_load_file(dirname(__DIR__, 3) . '/etc/backend/menu.xml');
        self::assertNotFalse($menu);
        self::assertSame($source, (string)$menu->menu['source']);
        self::assertSame('设备管理', (string)$menu->menu['title']);
        self::assertSame('session-manager/backend/device', (string)$menu->menu['action']);
        self::assertSame('Weline_Backend::user_permission_group', (string)$menu->menu['parent']);

        $environment = require dirname(__DIR__, 3) . '/etc/env.php';
        self::assertSame('session-manager', $environment['router'] ?? null);

        $provider = (new \ReflectionClass(SessionManagerQueryProvider::class))->newInstanceWithoutConstructor();
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            if (($operation['auth'] ?? '') === 'backend') {
                self::assertSame($source, $operation['backend_acl']['source_id'] ?? null);
            }
        }
    }

    public function testBrowserOperationsExposeOnlySelfServiceIdentityInputs(): void
    {
        $provider = (new \ReflectionClass(SessionManagerQueryProvider::class))->newInstanceWithoutConstructor();
        $descriptor = $provider->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        self::assertSame([
            'listFrontendDevices',
            'revokeFrontendDevice',
            'listBackendDevices',
            'revokeBackendDevice',
        ], array_keys($operations));

        foreach (['listFrontendDevices', 'revokeFrontendDevice'] as $name) {
            self::assertTrue($operations[$name]['frontend']);
            self::assertSame('customer', $operations[$name]['auth']);
            self::assertArrayNotHasKey('backend_acl', $operations[$name]);
        }
        foreach (['listBackendDevices', 'revokeBackendDevice'] as $name) {
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame(
                ['kind' => 'source', 'source_id' => 'Weline_SessionManager::device_manage_self'],
                $operations[$name]['backend_acl'],
            );
        }

        foreach ($operations as $operation) {
            $paramNames = array_column($operation['params'], 'name');
            self::assertNotContains('user_id', $paramNames);
            self::assertNotContains('principal_id', $paramNames);
            self::assertNotContains('area', $paramNames);
            foreach ($operation['params'] as $param) {
                self::assertNotSame('', trim((string)($param['description'] ?? '')));
            }
        }
    }

    public function testPersistenceFailuresAreSanitizedBeforeReturningToBrowserGateway(): void
    {
        $repository = $this->createMock(DeviceRepositoryInterface::class);
        $repository->method('listDevices')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000] private database detail'),
        );
        $repository->method('findDeviceByPublicId')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000] private database detail'),
        );
        $devices = new AuthenticatedDeviceRegistry(
            $repository,
            $this->createMock(DeviceMetadataProviderInterface::class),
        );
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getUserId')->willReturn(7);
        $session->method('getId')->willReturn('browser-query-session');
        $session->method('get')->willReturn(null);
        $session->method('getSession')->willReturn($this->createMock(SessionInterface::class));
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createFrontendSession')->willReturn($session);
        $provider = new SessionManagerQueryProvider($devices, $factory);

        foreach ([
            ['listFrontendDevices', []],
            ['revokeFrontendDevice', ['device_id' => 'public-device-id']],
        ] as [$operation, $params]) {
            try {
                $provider->execute($operation, $params);
                self::fail($operation . ' must not expose a persistence failure.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('设备管理服务', $exception->getMessage());
                self::assertStringNotContainsString('SQLSTATE', $exception->getMessage());
            }
        }
    }

    public function testBackendIdentityUsesAttestationAwareRequestFactory(): void
    {
        $repository = $this->createMock(DeviceRepositoryInterface::class);
        $repository->method('listDevices')->willReturn([]);
        $devices = new AuthenticatedDeviceRegistry(
            $repository,
            $this->createMock(DeviceMetadataProviderInterface::class),
        );

        $backendSession = $this->createMock(AuthenticatedSessionInterface::class);
        $backendSession->method('isLoggedIn')->willReturn(true);
        $backendSession->method('getUserId')->willReturn(7);
        $backendSession->method('getId')->willReturn('attested-backend-session');
        $backendSession->method('get')->willReturn(null);
        $backendSession->method('getSession')->willReturn($this->createMock(SessionInterface::class));

        $requestFactory = $this->createMock(SessionFactory::class);
        $requestFactory->expects(self::once())
            ->method('createBackendSession')
            ->willReturn($backendSession);
        $injectedFactory = $this->createMock(SessionFactory::class);
        $injectedFactory->expects(self::never())->method('createBackendSession');

        $instance = new \ReflectionProperty(SessionFactory::class, 'instance');
        $previous = $instance->getValue();
        $instance->setValue(null, $requestFactory);
        try {
            $result = (new SessionManagerQueryProvider($devices, $injectedFactory))
                ->execute('listBackendDevices');
            self::assertTrue($result['success']);
            self::assertSame([], $result['items']);
        } finally {
            $instance->setValue(null, $previous);
        }
    }
}
