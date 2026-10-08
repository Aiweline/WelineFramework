<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Acl;

use PHPUnit\Framework\TestCase;

final class RoleAclEntriesProviderContractTest extends TestCase
{
    public function testDeliveryAccessPolicyResolvesFrameworkAclInterface(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Event/Async/Admin/DeliveryAccessPolicy.php'
        );
        self::assertStringContainsString('RoleAclEntriesProviderInterface', $src);
        self::assertStringNotContainsString('Acl\\Service\\AclService', $src);
    }

    public function testNoFrameworkBackendRestAliasFile(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/App/Controller/BackendRestController.php'
        );
    }
}
