<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Server\Extends\Module\Weline_Framework\Query\ServerQueryProvider;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Control\SharedStateAdminService;
use Weline\Server\Service\OptimizationGuideService;
use Weline\Server\Service\SslCertificateService;

final class ServerQueryProviderHostsAddTest extends TestCase
{
    private function createProvider(): ServerQueryProvider
    {
        return new ServerQueryProvider(
            $this->createMock(SslCertificateService::class),
            $this->createMock(OptimizationGuideService::class),
            $this->createMock(BackendStatusService::class),
            $this->createMock(IpcControlGateway::class),
            $this->createMock(BroadcastControlDispatchService::class),
            $this->createMock(SharedStateAdminService::class),
            $this->createMock(SslCertificate::class),
        );
    }

    public function testHostsAddRejectsNonWelineLocalDomain(): void
    {
        $provider = $this->createProvider();

        $result = $provider->execute('hostsAdd', [
            'domain' => 'apk-seo.example.com',
        ]);

        self::assertIsArray($result);
        self::assertFalse((bool)($result['success'] ?? true));
        self::assertSame('apk-seo.example.com', $result['domain'] ?? null);
    }

    public function testHostsAddRejectsBareRootDomain(): void
    {
        $provider = $this->createProvider();

        $result = $provider->execute('hostsAdd', [
            'domain' => 'weline.local',
        ]);

        self::assertIsArray($result);
        self::assertFalse((bool)($result['success'] ?? true));
        self::assertSame('weline.local', $result['domain'] ?? null);
    }

    public function testEnsureLocalWildcardCertificateRejectsNonWildcardTarget(): void
    {
        $provider = $this->createProvider();

        $result = $provider->execute('ensureLocalWelineWildcardCertificate', [
            'domain' => 'apk-seo-d4de8e.weline.local',
        ]);

        self::assertIsArray($result);
        self::assertFalse((bool)($result['success'] ?? true));
        self::assertSame('apk-seo-d4de8e.weline.local', $result['domain'] ?? null);
    }
}
