<?php

declare(strict_types=1);

namespace Weline\Acl\Service {
    /**
     * Namespace-scoped w_cache() stub: inside Weline\Acl\Service, unqualified
     * w_cache('acl') resolves here first, giving the test an in-memory pool.
     */
    function w_cache(string $scope): AclSharedFactsPoolStub
    {
        return AclSharedFactsPoolStub::instance();
    }

    final class AclSharedFactsPoolStub
    {
        private static ?self $instance = null;
        /** @var array<string, mixed> */
        private array $data = [];
        public int $gets = 0;
        public int $sets = 0;
        public int $clears = 0;

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public static function resetInstance(): void
        {
            self::$instance = new self();
        }

        public function get(string $key): mixed
        {
            $this->gets++;
            return $this->data[$key] ?? false;
        }

        public function set(string $key, mixed $value, int $ttl = 0): bool
        {
            $this->sets++;
            $this->data[$key] = $value;
            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->data[$key]);
            return true;
        }

        public function clear(): bool
        {
            $this->clears++;
            $this->data = [];
            return true;
        }
    }
}

namespace Weline\Acl\Test\Unit\Service {

    use PHPUnit\Framework\TestCase;
    use Weline\Acl\Model\Acl;
    use Weline\Acl\Model\Role;
    use Weline\Acl\Model\RoleAccess;
    use Weline\Acl\Service\AclService;
    use Weline\Acl\Service\AclSharedFactsPoolStub;
    use Weline\Framework\Context;
    use Weline\Framework\Event\EventsManager;
    use Weline\Framework\Manager\ObjectManager;

    final class AclServiceSharedFactsTest extends TestCase
    {
        private mixed $originalContext;

        protected function setUp(): void
        {
            $this->originalContext = Context::getCurrent();
            Context::enter(new Context());
            AclSharedFactsPoolStub::resetInstance();
            AclService::resetRequestCache();
            AclService::clearProcessCache();
        }

        protected function tearDown(): void
        {
            Context::leave();
            if ($this->originalContext !== null) {
                Context::enter($this->originalContext);
            }
        }

        public function testRoleAclEntriesServedFromSharedPoolAcrossProcessResets(): void
        {
            $rows = [
                ['role_id' => 7, 'source_id' => 'Weline_Acl::menu_dashboard', 'route' => 'admin/dashboard', 'method' => 'GET', 'type' => 'menus', 'is_backend' => 1],
            ];
            $roleAccess = $this->getMockBuilder(RoleAccess::class)->disableOriginalConstructor()
                ->onlyMethods(['getRoleAccessListArrayByRoleId'])->getMock();
            $dbCalls = 0;
            $roleAccess->method('getRoleAccessListArrayByRoleId')
                ->willReturnCallback(static function () use ($rows, &$dbCalls): array {
                    $dbCalls++;
                    return $rows;
                });

            $em = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()
                ->onlyMethods(['dispatch', 'getEventObservers'])->getMock();
            $em->method('dispatch')->willReturnArgument(0);
            $em->method('getEventObservers')->willReturn([]);
            $emRef = $em;
            ObjectManager::setInstance(EventsManager::class, $emRef);

            $service = new AclService(
                $this->createStub(Role::class),
                $roleAccess,
                $this->createStub(Acl::class),
            );

            $pool = AclSharedFactsPoolStub::instance();

            $first = $service->getRoleAclEntries(7);
            self::assertSame($rows, $first);
            self::assertSame(1, $dbCalls, 'First call must read DB once.');
            self::assertSame(1, $pool->sets, 'DB rows must be written to the shared pool.');

            // Simulate a cold worker: request + process layers dropped, shared survives.
            AclService::resetRequestCache();
            AclService::clearProcessCache();

            $second = $service->getRoleAclEntries(7);
            self::assertSame($rows, $second);
            self::assertSame(1, $dbCalls, 'Shared hit must not re-query DB.');
            self::assertGreaterThanOrEqual(2, $pool->gets);

            // Same-process invalidation must bump the shared generation key and
            // force a fresh DB read (old-generation entries become unreachable).
            AclService::invalidateAclFacts();
            self::assertNotFalse(
                $pool->get('acl.service.facts_generation'),
                'invalidateAclFacts must bump the shared generation key.'
            );

            $third = $service->getRoleAclEntries(7);
            self::assertSame($rows, $third);
            self::assertSame(2, $dbCalls, 'After a generation bump the next call must hit DB.');

            // A generation bump by ANOTHER worker (locals left populated on
            // purpose) must be observed on the next read: this worker drops its
            // local copies and re-reads DB under the new generation.
            $dbCallsBefore = $dbCalls;
            $pool->set('acl.service.facts_generation', 'external-writer-gen');
            $fourth = $service->getRoleAclEntries(7);
            self::assertSame($rows, $fourth);
            self::assertSame($dbCallsBefore + 1, $dbCalls, 'Foreign generation bump must invalidate local facts.');
        }
    }
}
