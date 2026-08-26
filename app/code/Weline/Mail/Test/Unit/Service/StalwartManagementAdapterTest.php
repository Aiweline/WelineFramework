<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\StalwartManagementAdapter;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class StalwartManagementAdapterTest extends TestCase
{
    public function testBuildUserObjectUsesV016SchemaAndByteQuota(): void
    {
        $adapter = new StalwartManagementAdapter();
        $object = $adapter->buildUserObject('alice', 'domain-id', 'long-password-123', 2048);

        self::assertSame('User', $object['@type']);
        self::assertSame('alice', $object['name']);
        self::assertSame('domain-id', $object['domainId']);
        self::assertSame(2147483648, $object['quotas']->maxDiskQuota);
        self::assertSame('Password', $object['credentials']->{'0'}['@type']);
        self::assertSame('Inherit', $object['permissions']['@type']);
        self::assertSame('User', $object['roles']['@type']);
    }

    public function testBuildUserObjectRejectsWeakPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StalwartManagementAdapter())->buildUserObject('alice', 'domain-id', 'short', 1024);
    }
}
