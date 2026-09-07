<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiSiteProvisioning;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\AiSiteLocalDomainReadinessService;
use Weline\Websites\Service\LocalWelineHostsSyncService;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

final class AiSiteLocalDomainReadinessServiceTest extends TestCase
{
    public function testPreparedDomainPassesActualLoopbackAndWildcardCertificateChecks(): void
    {
        $service = $this->service(
            static fn (string $domain): array => $domain === 'prepared-demo.test.weline.com'
                ? ['::1', '127.0.0.1']
                : [],
            static function (string $hostname): array {
                self::assertSame('*.test.weline.com', $hostname);

                return self::activeCertificate();
            },
            static fn (int $limit): array => [[
                DomainPool::schema_fields_DOMAIN => 'prepared-demo.test.weline.com',
            ]]
        );

        $result = $service->inspectCandidates();

        self::assertTrue($result['can_start']);
        self::assertSame('OK', $result['code']);
        self::assertSame('prepared-demo.test.weline.com', $result['domain']);
        self::assertSame(['127.0.0.1', '::1'], $result['resolved_ips']);
        self::assertTrue($result['certificate_ready']);
        self::assertTrue($result['candidates'][0]['prepared']);
        self::assertSame('prepared_pool', $result['candidates'][0]['source']);
        self::assertSame('ready', $result['candidates'][0]['preparation_status']);
    }

    public function testResolutionConflictFailsClosedEvenWhenOneAddressIsLoopback(): void
    {
        $service = $this->service(
            static fn (string $domain): array => ['127.0.0.1', '203.0.113.8'],
            static fn (string $hostname): array => self::activeCertificate()
        );

        $result = $service->inspect('conflict-demo.test.weline.com');

        self::assertFalse($result['can_start']);
        self::assertSame('TEST_DOMAIN_RESOLUTION_CONFLICT', $result['code']);
        self::assertSame(['127.0.0.1', '203.0.113.8'], $result['resolved_ips']);
        self::assertTrue($result['certificate_ready']);
        self::assertTrue($result['requires_admin']);
        self::assertSame(
            "php bin/w server:hosts:add 'conflict-demo.test.weline.com'",
            $result['preparation_command']
        );
    }

    public function testUnavailableWildcardCertificateBlocksOtherwiseReadyDomain(): void
    {
        $service = $this->service(
            static fn (string $domain): array => ['127.0.0.1'],
            static fn (string $hostname): array => [
                'cert_id' => 9,
                'status' => 'active',
                'is_expired' => true,
                'expires_at' => '2020-01-01 00:00:00',
            ]
        );

        $result = $service->inspect('certificate-demo.test.weline.com');

        self::assertFalse($result['can_start']);
        self::assertSame('TEST_DOMAIN_CERTIFICATE_UNAVAILABLE', $result['code']);
        self::assertFalse($result['certificate_ready']);
        self::assertFalse($result['requires_admin']);
        self::assertSame('php bin/w server:start', $result['preparation_command']);
    }

    public function testBatchPrioritizesVerifiedPreparedFallbackAndMarksAiCandidateUnprepared(): void
    {
        $certificateCalls = 0;
        $service = $this->service(
            static fn (string $domain): array => match ($domain) {
                'ready-pool.test.weline.com', 'ai-ready.test.weline.com' => ['127.0.0.1'],
                'stale-pool.test.weline.com' => ['198.51.100.7'],
                default => [],
            },
            static function (string $hostname) use (&$certificateCalls): array {
                $certificateCalls++;

                return self::activeCertificate();
            },
            static fn (int $limit): array => [
                [DomainPool::schema_fields_DOMAIN => 'stale-pool.test.weline.com'],
                [DomainPool::schema_fields_DOMAIN => 'ready-pool.test.weline.com'],
            ]
        );

        $result = $service->inspectCandidates([
            'brand-new.test.weline.com',
            'ai-ready.test.weline.com',
        ]);

        self::assertTrue($result['can_start']);
        self::assertSame('ready-pool.test.weline.com', $result['domain']);
        self::assertSame(1, $certificateCalls, 'A batch must resolve the shared wildcard certificate once.');
        self::assertSame('ready-pool.test.weline.com', $result['candidates'][0]['domain']);
        self::assertSame('prepared_pool', $result['candidates'][0]['source']);
        self::assertSame('ai-ready.test.weline.com', $result['candidates'][1]['domain']);
        self::assertSame('ai_candidate', $result['candidates'][1]['source']);

        $unpreparedAi = null;
        foreach ($result['candidates'] as $candidate) {
            if (($candidate['domain'] ?? '') === 'brand-new.test.weline.com') {
                $unpreparedAi = $candidate;
                break;
            }
        }
        self::assertIsArray($unpreparedAi);
        self::assertFalse($unpreparedAi['prepared']);
        self::assertFalse($unpreparedAi['can_start']);
        self::assertSame('ai_candidate', $unpreparedAi['source']);
        self::assertSame('unprepared', $unpreparedAi['preparation_status']);
    }

    public function testPrepareRequiresConfirmationBeforeWritingHosts(): void
    {
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::never())->method('ensureHostsInjected');
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');

        $service = new AiSiteLocalDomainReadinessService(
            new DomainPool(),
            static fn (): array => [],
            static fn (): array => self::activeCertificate(),
            null,
            $hosts,
            $certs
        );

        $result = $service->prepare('auto-prep.test.weline.com', false);

        self::assertFalse($result['can_start']);
        self::assertSame('LOCAL_DOMAIN_PREPARE_CONFIRMATION_REQUIRED', $result['code']);
        self::assertFalse($result['prepared_now']);
    }

    public function testPrepareInjectsHostsAndCertificateThenPassesInspect(): void
    {
        $resolved = [];
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::once())
            ->method('ensureHostsInjected')
            ->with('auto-prep.test.weline.com')
            ->willReturnCallback(static function () use (&$resolved): array {
                $resolved = ['127.0.0.1'];

                return ['success' => true, 'message' => 'hosts ok'];
            });
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::once())
            ->method('ensureWildcardCertificateForDomain')
            ->with('auto-prep.test.weline.com', 0)
            ->willReturn(['success' => true, 'message' => 'cert ok']);

        $service = new AiSiteLocalDomainReadinessService(
            new DomainPool(),
            static function () use (&$resolved): array {
                return $resolved;
            },
            static fn (): array => self::activeCertificate(),
            null,
            $hosts,
            $certs
        );

        self::assertFalse($service->inspect('auto-prep.test.weline.com')['can_start']);
        $result = $service->prepare('auto-prep.test.weline.com', true);

        self::assertTrue($result['can_start']);
        self::assertTrue($result['prepared_now']);
        self::assertSame('OK', $result['code']);
        self::assertSame(['127.0.0.1'], $result['resolved_ips']);
    }

    public function testPrepareAuthorizationPendingReturnsStableStatusWithoutLongAdminCommand(): void
    {
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::once())
            ->method('ensureHostsInjected')
            ->with('pending-prep.test.weline.com')
            ->willReturn([
                'success' => false,
                'needs_admin' => true,
                'authorization_pending' => true,
                'authorization_already_started' => false,
                'target_domain' => 'pending-prep.test.weline.com',
                'message' => 'authorization pending',
            ]);
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');
        $service = new AiSiteLocalDomainReadinessService(
            new DomainPool(),
            static fn (): array => [],
            static fn (): array => self::activeCertificate(),
            null,
            $hosts,
            $certs
        );

        $result = $service->prepare('pending-prep.test.weline.com', true);

        self::assertFalse($result['can_start']);
        self::assertSame('TEST_DOMAIN_HOSTS_AUTHORIZATION_PENDING', $result['code']);
        self::assertTrue($result['authorization_pending']);
        self::assertSame('', $result['preparation_command']);
        self::assertSame('pending-prep.test.weline.com', $result['domain']);
        self::assertArrayNotHasKey('command', $result['hosts']);
    }

    public function testPrepareNeverPassesEncodedPlatformCommandToBrowserResult(): void
    {
        $hosts = $this->createMock(LocalWelineHostsSyncService::class);
        $hosts->expects(self::once())
            ->method('ensureHostsInjected')
            ->willReturn([
                'success' => false,
                'needs_admin' => true,
                'command' => 'encoded-platform-elevation-payload',
                'message' => 'administrator required',
            ]);
        $certs = $this->createMock(LocalWelineWildcardCertificateService::class);
        $certs->expects(self::never())->method('ensureWildcardCertificateForDomain');
        $service = new AiSiteLocalDomainReadinessService(
            new DomainPool(),
            static fn (): array => [],
            static fn (): array => self::activeCertificate(),
            null,
            $hosts,
            $certs
        );

        $result = $service->prepare('manual-prep.test.weline.com', true);

        self::assertFalse($result['can_start']);
        self::assertSame(
            "php bin/w server:hosts:add 'manual-prep.test.weline.com'",
            $result['preparation_command']
        );
        self::assertStringNotContainsString(
            'encoded-platform-elevation-payload',
            $result['preparation_command']
        );
    }

    /**
     * @param \Closure(string):array $hostResolver
     * @param \Closure(string):mixed $certificateResolver
     * @param null|\Closure(int):array $candidateLoader
     */
    private function service(
        \Closure $hostResolver,
        \Closure $certificateResolver,
        ?\Closure $candidateLoader = null
    ): AiSiteLocalDomainReadinessService {
        return new AiSiteLocalDomainReadinessService(
            new DomainPool(),
            $hostResolver,
            $certificateResolver,
            $candidateLoader
        );
    }

    /** @return array<string,mixed> */
    private static function activeCertificate(): array
    {
        return [
            'cert_id' => 101,
            'status' => 'active',
            'is_expired' => false,
            'expires_at' => '2099-12-31 23:59:59',
        ];
    }
}
