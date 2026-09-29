<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Cdn\Extends\Module\Weline_Framework\Query\CdnQueryProvider;
use Weline\Cdn\Service\CdnAdminQueryService;
use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Cdn\Service\FpcPolicyManagementService;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\ScopedAccountBindingService;
use Weline\Cdn\Test\Unit\Double\InMemoryScopedAccountBindingRepository;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CdnFpcQueryOperationsTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(FpcPolicyManagementService::class);
        ObjectManager::removeInstance(CdnRuleCollector::class);
        ObjectManager::removeInstance(ScopeHierarchyInterface::class);
    }

    public function testProviderPreservesScopeAndMissingVersusNullFieldsAtTheManagementBoundary(): void
    {
        // 隔离声明存储和队列 I/O；验证真实 API 的 operation 映射与字段保真。
        $management = new class {
            public function __call(string $method, array $arguments): array
            {
                return ['success' => true, 'message' => '', 'data' => ['method' => $method, 'params' => $arguments[0]]];
            }
        };
        ObjectManager::setInstance(FpcPolicyManagementService::class, $management);
        $admin = new CdnAdminQueryService((new ReflectionClass(Log::class))->newInstanceWithoutConstructor());
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(CdnQueryProvider::class))->getProperty('adminQueryService')->setValue($provider, $admin);
        foreach ([
            'listFpcPolicies' => 'listPolicies',
            'saveFpcPolicyOverride' => 'saveOverride',
            'restoreFpcPolicyInheritance' => 'restoreInheritance',
            'collectFpcPolicies' => 'collectDeclarations',
            'listFpcSyncRecords' => 'listSyncRecords',
            'retryFpcSync' => 'retrySync',
        ] as $operation => $method) {
            $params = ['target_scope' => 'Channel:12', 'store_mode' => 'test', 'declaration_id' => str_repeat('a', 64), 'enabled' => null];
            $result = $provider->execute($operation, $params);
            self::assertSame(['success' => true, 'message' => '', 'data' => ['method' => $method, 'params' => $params]], $result);
            self::assertArrayNotHasKey('ttl', $result['data']['params']);
        }
    }

    public function testApiRuleCollectionUsesCollectAllAndIncludesTheFpcSyncRequest(): void
    {
        $collector = new class {
            public function collectAll(): array { return [['path_pattern' => '/public/*']]; }
        };
        $management = new class {
            public function collectDeclarations(array $params): array
            {
                return ['success' => true, 'message' => '', 'data' => ['changed' => true, 'desired_version' => 2, 'origin_version' => 1, 'queue_ids' => [10], 'sync_ids' => [3]]];
            }
        };
        ObjectManager::setInstance(CdnRuleCollector::class, $collector);
        ObjectManager::setInstance(FpcPolicyManagementService::class, $management);
        $service = new CdnAdminQueryService($this->createMock(Log::class));
        self::assertSame([
            'success' => true, 'message' => (string)__('收集完成'),
            'data' => ['rules' => [['path_pattern' => '/public/*']], 'fpc_policy' => ['changed' => true, 'desired_version' => 2, 'origin_version' => 1, 'queue_ids' => [10], 'sync_ids' => [3]]],
        ], $service->collectApiRules([]));
    }

    public function testFailedFpcCollectionIsReturnedWithoutPretendingThatSynchronizationSucceeded(): void
    {
        $collector = new class { public function collectAll(): array { return []; } };
        $management = new class {
            public function collectDeclarations(array $params): array
            {
                return ['success' => false, 'message' => 'not published', 'error_code' => 'origin_publish_failed'];
            }
        };
        ObjectManager::setInstance(CdnRuleCollector::class, $collector);
        ObjectManager::setInstance(FpcPolicyManagementService::class, $management);
        $service = new CdnAdminQueryService($this->createMock(Log::class));
        self::assertSame(['success' => false, 'message' => 'not published', 'error_code' => 'origin_publish_failed'], $service->collectApiRules([]));
    }

    public function testScopeAccountBindingAndRestoreNotifyTheSameModeWithOldAndNewAccounts(): void
    {
        $hierarchy = $this->createMock(ScopeHierarchyInterface::class);
        $hierarchy->method('toStorageScope')->willReturn('registered.scope.projection');
        ObjectManager::setInstance(ScopeHierarchyInterface::class, $hierarchy);
        $bindings = new ScopedAccountBindingService(new SystemConfigScopeResolver(), new InMemoryScopedAccountBindingRepository());
        $bindings->bind(ScopeIdentity::global(), 'cloudflare', 1);
        $manager = $this->createMock(AccountManager::class);
        $manager->method('bindAccountToScope')->willReturnCallback(static fn (int $id, ScopeIdentity $scope, string $adapter, string $media, string $alias) => $bindings->bind($scope, $adapter, $id, $media, $alias));
        $manager->method('restoreScopeInheritance')->willReturnCallback(static fn (ScopeIdentity $scope, string $adapter) => $bindings->restoreInheritance($scope, $adapter));
        $management = new class {
            public function notifyScopeBindingChange(array $change): array {
                return ['success' => true, 'message' => 'accepted', 'data' => $change];
            }
        };
        ObjectManager::setInstance(FpcPolicyManagementService::class, $management);
        $admin = new CdnAdminQueryService($this->createMock(Log::class));
        $reflection = new ReflectionClass(CdnQueryProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        foreach (['accountManager' => $manager, 'scopedAccountBindingService' => $bindings, 'adminQueryService' => $admin] as $property => $value) {
            $reflection->getProperty($property)->setValue($provider, $value);
        }
        $params = ['scope_kind' => 'store', 'website_id' => 3, 'website_code' => 'shop', 'store_code' => 'main', 'store_mode' => 'test', 'adapter' => 'cloudflare', 'account_id' => 9];
        $bound = $provider->execute('bindAccountToScope', $params);
        self::assertTrue($bound['success']);
        self::assertArrayHasKey('sync', $bound);
        self::assertSame('test', $bound['sync']['data']['store_mode']);
        self::assertSame('registered.scope.projection', $bound['sync']['data']['target_scope']);
        self::assertSame(9, $bound['sync']['data']['after']['account_id']);
        self::assertNull($bound['sync']['data']['before']);
        self::assertArrayNotHasKey('credentials', $bound['sync']['data']['after']);
        $restored = $provider->execute('restoreScopeInheritance', $params);
        self::assertTrue($restored['restored']);
        self::assertSame('test', $restored['sync']['data']['store_mode']);
        self::assertSame(9, $restored['sync']['data']['before']['account_id']);
        self::assertNull($restored['sync']['data']['after']);
        self::assertSame($bound['sync']['data']['target_scope'], $restored['sync']['data']['target_scope']);
    }
}
