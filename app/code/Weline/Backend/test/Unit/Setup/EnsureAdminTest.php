<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Setup\EnsureAdmin;

class EnsureAdminTest extends TestCase
{
    public function testEnsureSkipsSavingUserRoleWhenAdminRelationAlreadyExists(): void
    {
        $backendUser = new class() extends BackendUser {
            public function __construct()
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 1;
            }
        };

        $role = new class() extends Role {
            public function __construct()
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 1;
            }
        };

        $state = (object) ['saveCalled' => false, 'savedUserId' => 0, 'savedRoleId' => 0];
        $userRole = $this->createUserRoleStub(true, $state);

        $service = new EnsureAdmin($backendUser, $role, $userRole);
        $service->ensure();

        self::assertFalse($state->saveCalled);
    }

    public function testEnsureCreatesUserRoleWhenAdminRelationIsMissing(): void
    {
        $backendUser = new class() extends BackendUser {
            public function __construct()
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 1;
            }
        };

        $role = new class() extends Role {
            public function __construct()
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 1;
            }
        };

        $state = (object) ['saveCalled' => false, 'savedUserId' => 0, 'savedRoleId' => 0];
        $userRole = $this->createUserRoleStub(false, $state);

        $service = new EnsureAdmin($backendUser, $role, $userRole);
        $service->ensure();

        self::assertTrue($state->saveCalled);
        self::assertSame(1, $state->savedUserId);
        self::assertSame(1, $state->savedRoleId);
    }

    private function createUserRoleStub(bool $exists, object $state): UserRole
    {
        return new class($exists, $state) extends UserRole {
            public function __construct(
                private readonly bool $exists,
                private readonly object $state
            )
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

            public function find(): static
            {
                return $this;
            }

            public function fetch(): static
            {
                if ($this->exists) {
                    $this->state->savedUserId = 1;
                    $this->state->savedRoleId = 1;
                }

                return $this;
            }

            public function clearData(bool $with_query = true): static
            {
                $this->state->savedUserId = 0;
                $this->state->savedRoleId = 0;
                return $this;
            }

            public function setUserId(int $user_id): static
            {
                $this->state->savedUserId = $user_id;
                return $this;
            }

            public function setRoleId(int $role_id): static
            {
                $this->state->savedRoleId = $role_id;
                return $this;
            }

            public function getUserId()
            {
                return $this->state->savedUserId;
            }

            public function getRoleId()
            {
                return $this->state->savedRoleId;
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                $this->state->saveCalled = true;
                return 1;
            }
        };
    }
}
