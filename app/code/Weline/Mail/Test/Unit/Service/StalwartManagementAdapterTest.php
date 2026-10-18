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

    public function testManagementCredentialDualPathAndUpsertLocked(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StalwartManagementAdapter.php');
        self::assertStringContainsString('/etc/weline/mail-admin.key', $src);
        self::assertStringContainsString('var/mail/mail-admin.key', $src);
        self::assertStringContainsString('function upsertManagementCredential', $src);
        self::assertStringContainsString('function hasManagementCredential', $src);
        self::assertStringContainsString('resolveReadableCredentialPair', $src);
    }

    public function testImportManagedHostnameCertificateSourceLocksProvenShape(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StalwartManagementAdapter.php');
        self::assertStringContainsString('function importManagedHostnameCertificate', $src);
        self::assertStringContainsString('x:Certificate/set', $src);
        self::assertStringContainsString("'@type' => 'Text'", $src);
        self::assertStringContainsString("'secret' => \$privateKeyPem", $src);
        self::assertStringContainsString("\$setArgs['destroy'] = \$destroy", $src);
        self::assertStringContainsString("ReloadTlsCertificates", $src);
        self::assertStringContainsString("ReloadSettings", $src);
        self::assertStringContainsString('certificateIdsCoveringHostname', $src);
        self::assertStringNotContainsString('AcmeProvider', $src);
        self::assertStringContainsString('function deleteAccount', $src);
        self::assertStringContainsString("'destroy' => [\$accountId]", $src);
        self::assertStringContainsString('function listPublishableDkimDnsRecords', $src);
        self::assertStringContainsString('x:DkimSignature/query', $src);
        self::assertStringContainsString('x:DkimSignature/get', $src);
        self::assertStringContainsString("'active' => true", $src);
        self::assertStringContainsString("'pending' => true", $src);
        self::assertStringContainsString("'retiring' => true", $src);
    }
}
