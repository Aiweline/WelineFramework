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
}
