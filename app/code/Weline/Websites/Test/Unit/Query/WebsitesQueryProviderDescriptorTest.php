<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Websites\Extends\Module\Weline_Framework\Query\WebsitesQueryProvider;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class WebsitesQueryProviderDescriptorTest extends TestCase
{
    public function testDomainAdminBridgeIsBrowserReachableOnlyWithDomainServiceAcl(): void
    {
        $provider = (new ReflectionClass(WebsitesQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        self::assertArrayHasKey('adminRequest', $operations);
        self::assertTrue($operations['adminRequest']['frontend']);
        self::assertTrue($operations['adminRequest']['backend']);
        self::assertSame('backend', $operations['adminRequest']['auth']);
        self::assertSame('write', $operations['adminRequest']['mode']);
        self::assertSame(
            ['kind' => 'source', 'source_id' => 'Weline_Websites::domain_service'],
            $operations['adminRequest']['backend_acl'],
        );
    }

    public function testWebsiteBackupUsesDedicatedBackendAclOperation(): void
    {
        $provider = (new ReflectionClass(WebsitesQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        self::assertArrayHasKey('manageWebsiteBackup', $operations);
        self::assertTrue($operations['manageWebsiteBackup']['frontend']);
        self::assertTrue($operations['manageWebsiteBackup']['backend']);
        self::assertSame('backend', $operations['manageWebsiteBackup']['auth']);
        self::assertSame('write', $operations['manageWebsiteBackup']['mode']);
        self::assertSame(
            ['kind' => 'source', 'source_id' => 'Weline_Websites::website_backup_create'],
            $operations['manageWebsiteBackup']['backend_acl'],
        );
    }
}
