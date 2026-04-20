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
        $this->assertSame(
            SslCertificateService::PROVIDER_LOCAL_CA,
            $normalize->invoke($service, 'local-ca')
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

        $this->assertTrue($m->invoke($service, '*.weline.test'));
        $this->assertTrue($m->invoke($service, 'p11005ce4.weline.test'));
        $this->assertTrue($m->invoke($service, 'shop-1.weline.test'));
        $this->assertTrue($m->invoke($service, '*.weline.localhost'));
        $this->assertTrue($m->invoke($service, 'p11005ce4.weline.localhost'));

        $this->assertFalse($m->invoke($service, 'weline.test'));
        $this->assertFalse($m->invoke($service, 'weline.localhost'));
        $this->assertFalse($m->invoke($service, 'example.com'));
        $this->assertFalse($m->invoke($service, ''));
    }

    public function testCertificateStorageSegmentForFilesystemPlainDomain(): void
    {
        $this->assertSame(
            'p11005ce4.weline.test',
            SslCertificateService::certificateStorageSegmentForFilesystem('p11005ce4.weline.test')
        );
    }

    public function testCertificateStorageSegmentForFilesystemWildcard(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertSame(
                '_wildcard_.weline.test',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.test')
            );
        } else {
            $this->assertSame(
                '*.weline.test',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.test')
            );
        }
    }

    public function testCertificateStorageSegmentCandidatesForProbeWildcard(): void
    {
        $c = SslCertificateService::certificateStorageSegmentCandidatesForProbe('*.weline.test');
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertContains('_wildcard_.weline.test', $c);
            $this->assertContains('*.weline.test', $c);
        } else {
            $this->assertSame(['*.weline.test'], $c);
        }
    }

    public function testLogicalDomainFromStorageSegment(): void
    {
        $this->assertSame(
            '*.weline.test',
            SslCertificateService::logicalDomainFromStorageSegment('_wildcard_.weline.test')
        );
        $this->assertSame(
            'p1.weline.test',
            SslCertificateService::logicalDomainFromStorageSegment('p1.weline.test')
        );
    }

    public function testGetIssuerByProviderSupportsLocalCa(): void
    {
        $service = new SslCertificateService();

        $this->assertSame(
            SslCertificateService::ISSUER_LOCAL_CA,
            $service->getIssuerByProvider(SslCertificateService::PROVIDER_LOCAL_CA)
        );
    }

    public function testInferProviderByIssuerRecognizesLocalCaIssuer(): void
    {
        $service = new SslCertificateService();
        $infer = new ReflectionMethod($service, 'inferProviderByIssuer');
        $infer->setAccessible(true);

        $this->assertSame(
            SslCertificateService::PROVIDER_LOCAL_CA,
            $infer->invoke($service, '', SslCertificateService::ISSUER_LOCAL_CA)
        );
    }
}
