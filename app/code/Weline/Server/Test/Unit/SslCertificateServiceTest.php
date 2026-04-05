<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\SslCertificateService;

class SslCertificateServiceTest extends TestCase
{
    public function testNormalizeProviderAliases(): void
    {
        $service = new SslCertificateService();
        $normalize = new ReflectionMethod($service, 'normalizeAcmeProvider');
        $normalize->setAccessible(true);
        
        $this->assertSame(
            SslCertificateService::PROVIDER_LETS_ENCRYPT,
            $normalize->invoke($service, "Let's Encrypt")
        );
        $this->assertSame(
            SslCertificateService::PROVIDER_LITESSL,
            $normalize->invoke($service, 'lite-ssl')
        );
        $this->assertSame(
            SslCertificateService::PROVIDER_SELF_SIGNED,
            $normalize->invoke($service, 'selfsigned')
        );
    }
    
    public function testResolveAcmeDirectoryByProvider(): void
    {
        $service = new SslCertificateService();
        $resolve = new ReflectionMethod($service, 'resolveAcmeDirectory');
        $resolve->setAccessible(true);
        
        $leProd = $resolve->invoke($service, SslCertificateService::PROVIDER_LETS_ENCRYPT, false);
        $leStaging = $resolve->invoke($service, SslCertificateService::PROVIDER_LETS_ENCRYPT, true);
        $liteProd = $resolve->invoke($service, SslCertificateService::PROVIDER_LITESSL, false);
        $liteStaging = $resolve->invoke($service, SslCertificateService::PROVIDER_LITESSL, true);
        
        $this->assertIsString($leProd);
        $this->assertStringContainsString('letsencrypt.org', $leProd);
        $this->assertIsString($leStaging);
        $this->assertStringContainsString('letsencrypt.org', $leStaging);
        $this->assertIsString($liteProd);
        $this->assertStringContainsString('sectigo.com', $liteProd);
        $this->assertNull($liteStaging);
    }

    public function testIsWelineLocalWildcardCandidateDomain(): void
    {
        $service = new SslCertificateService();
        $m = new ReflectionMethod($service, 'isWelineLocalWildcardCandidateDomain');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($service, '*.weline.local'));
        $this->assertTrue($m->invoke($service, 'p11005ce4.weline.local'));
        $this->assertTrue($m->invoke($service, 'shop-1.weline.local'));

        $this->assertFalse($m->invoke($service, 'weline.local'));
        $this->assertFalse($m->invoke($service, 'example.com'));
        $this->assertFalse($m->invoke($service, ''));
    }

    public function testCertificateStorageSegmentForFilesystemPlainDomain(): void
    {
        $this->assertSame(
            'p11005ce4.weline.local',
            SslCertificateService::certificateStorageSegmentForFilesystem('p11005ce4.weline.local')
        );
    }

    public function testCertificateStorageSegmentForFilesystemWildcard(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertSame(
                '_wildcard_.weline.local',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.local')
            );
        } else {
            $this->assertSame(
                '*.weline.local',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.local')
            );
        }
    }

    public function testCertificateStorageSegmentCandidatesForProbeWildcard(): void
    {
        $c = SslCertificateService::certificateStorageSegmentCandidatesForProbe('*.weline.local');
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertContains('_wildcard_.weline.local', $c);
            $this->assertContains('*.weline.local', $c);
        } else {
            $this->assertSame(['*.weline.local'], $c);
        }
    }

    public function testLogicalDomainFromStorageSegment(): void
    {
        $this->assertSame(
            '*.weline.local',
            SslCertificateService::logicalDomainFromStorageSegment('_wildcard_.weline.local')
        );
        $this->assertSame(
            'p1.weline.local',
            SslCertificateService::logicalDomainFromStorageSegment('p1.weline.local')
        );
    }
}
