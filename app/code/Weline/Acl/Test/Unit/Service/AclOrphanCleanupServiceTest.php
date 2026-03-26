<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Acl\Service\AclOrphanCleanupService;

class AclOrphanCleanupServiceTest extends TestCase
{
    public function testCleanupByActiveModulesDeletesNonCollectedAclRowsWithoutTypeError(): void
    {
        $acl = new class([
            [
                Acl::schema_fields_SOURCE_ID => 'Weline_Test::missing',
                Acl::schema_fields_MODULE => 'Weline_Test',
            ],
        ]) extends Acl {
            public bool $deleteCalled = false;

            public function __construct(private readonly array $rows)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function fields(...$args): static
            {
                return $this;
            }

            public function select(): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return $this->rows;
            }

            public function delete(): static
            {
                $this->deleteCalled = true;
                return $this;
            }

            public function fetch(): array
            {
                return [];
            }
        };

        $roleAccess = new class() extends RoleAccess {
            public bool $deleteCalled = false;

            public function __construct()
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function delete(): static
            {
                $this->deleteCalled = true;
                return $this;
            }

            public function fetch(): array
            {
                return [];
            }
        };

        $service = new AclOrphanCleanupService($acl, $roleAccess);

        self::assertSame(1, $service->cleanupByActiveModules(['Weline_Test'], []));
        self::assertTrue($acl->deleteCalled);
        self::assertTrue($roleAccess->deleteCalled);
    }
}
