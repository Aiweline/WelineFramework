<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\LocalWelineHostsSyncService;

final class LocalWelineHostsSyncServiceTest extends TestCase
{
    public function testIsEligibleDomainOnlyAcceptsSingleLevelWelineLocalSubdomain(): void
    {
        $service = new LocalWelineHostsSyncService();

        self::assertTrue($service->isEligibleDomain('apk-seo-d4de8e.weline.test'));
        self::assertTrue($service->isEligibleDomain('DEMO-123.weline.test'));
        self::assertTrue($service->isEligibleDomain('queued-phase-flow.local.test'));
        self::assertTrue($service->isEligibleDomain('demo-123.weline.localhost'));

        self::assertFalse($service->isEligibleDomain('weline.test'));
        self::assertFalse($service->isEligibleDomain('local.test'));
        self::assertFalse($service->isEligibleDomain('foo.bar.weline.test'));
        self::assertFalse($service->isEligibleDomain('apk-seo.local'));
        self::assertFalse($service->isEligibleDomain('apk-seo.example.com'));
        self::assertFalse($service->isEligibleDomain('localhost'));
    }

    public function testEnsureHostsInjectedUsesQueryExecutorOnlyForEligibleDomain(): void
    {
        $calls = [];
        $service = new LocalWelineHostsSyncService(
            static function (string $provider, string $operation, array $params) use (&$calls): array {
                $calls[] = [$provider, $operation, $params];
                return ['success' => true, 'message' => 'ok'];
            }
        );

        $ok = $service->ensureHostsInjected('apk-seo-d4de8e.weline.test');
        self::assertTrue((bool)($ok['success'] ?? false));
        self::assertCount(1, $calls);
        self::assertSame('server', $calls[0][0]);
        self::assertSame('hostsAdd', $calls[0][1]);
        self::assertSame('apk-seo-d4de8e.weline.test', $calls[0][2]['domain']);

        $localTest = $service->ensureHostsInjected('queued-phase-flow.local.test');
        self::assertTrue((bool)($localTest['success'] ?? false));
        self::assertCount(2, $calls);
        self::assertSame('queued-phase-flow.local.test', $calls[1][2]['domain']);

        $calls = [];
        $loopback = $service->ensureHostsInjected('demo-123.weline.localhost');
        self::assertTrue((bool)($loopback['success'] ?? false));
        self::assertTrue((bool)($loopback['skipped'] ?? false));
        self::assertCount(0, $calls);

        $calls = [];
        $skipped = $service->ensureHostsInjected('apk-seo.example.com');
        self::assertFalse((bool)($skipped['success'] ?? true));
        self::assertTrue((bool)($skipped['skipped'] ?? false));
        self::assertCount(0, $calls);
    }
}
