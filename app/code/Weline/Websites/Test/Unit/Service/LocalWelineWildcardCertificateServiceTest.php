<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\LocalWelineWildcardCertificateService;

final class LocalWelineWildcardCertificateServiceTest extends TestCase
{
    public function testEligibleDomainOnlyAcceptsSingleLabelWelineLocalSubdomains(): void
    {
        $service = new LocalWelineWildcardCertificateService();

        self::assertTrue($service->isEligibleDomain('apk-seo-d4de8e.weline.local'));
        self::assertTrue($service->isEligibleDomain('demo-123.weline.local'));

        self::assertFalse($service->isEligibleDomain('weline.local'));
        self::assertFalse($service->isEligibleDomain('*.weline.local'));
        self::assertFalse($service->isEligibleDomain('foo.bar.weline.local'));
        self::assertFalse($service->isEligibleDomain('foo.local'));
        self::assertFalse($service->isEligibleDomain('foo.example.com'));
    }

    public function testEnsureWildcardCertificateReusesExistingWildcardRecord(): void
    {
        $calls = [];
        $service = new LocalWelineWildcardCertificateService(
            static function (string $provider, string $operation, array $params) use (&$calls): array {
                $calls[] = [$provider, $operation, $params];
                if ($operation === 'resolveManagedCertificate') {
                    return [
                        'cert_id' => 1001,
                        'status' => 'active',
                        'is_expired' => false,
                    ];
                }

                return ['success' => false, 'message' => 'unexpected'];
            }
        );

        $result = $service->ensureWildcardCertificateForDomain('apk-seo-d4de8e.weline.local', 88);

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertTrue((bool)($result['reused'] ?? false));
        self::assertSame(LocalWelineWildcardCertificateService::WILDCARD_DOMAIN, $result['wildcard_domain'] ?? null);
        self::assertCount(1, $calls);
        self::assertSame('resolveManagedCertificate', $calls[0][1]);
    }

    public function testEnsureWildcardCertificateRequestsWildcardWhenMissing(): void
    {
        $calls = [];
        $service = new LocalWelineWildcardCertificateService(
            static function (string $provider, string $operation, array $params) use (&$calls): array {
                $calls[] = [$provider, $operation, $params];
                if ($operation === 'resolveManagedCertificate') {
                    return [];
                }
                if ($operation === 'ensureLocalWelineWildcardCertificate') {
                    return ['success' => true, 'message' => 'issued'];
                }

                return [];
            }
        );

        $result = $service->ensureWildcardCertificateForDomain('apk-seo-d4de8e.weline.local', 77);

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertSame(LocalWelineWildcardCertificateService::WILDCARD_DOMAIN, $result['wildcard_domain'] ?? null);
        self::assertCount(2, $calls);
        self::assertSame('ensureLocalWelineWildcardCertificate', $calls[1][1]);
        self::assertSame(77, $calls[1][2]['website_id']);
        self::assertSame(LocalWelineWildcardCertificateService::WILDCARD_DOMAIN, $calls[1][2]['domain']);
    }
}
