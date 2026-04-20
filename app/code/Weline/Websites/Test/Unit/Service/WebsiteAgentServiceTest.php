<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Service\DomainPurchaseService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\WebsiteAgentService;

class WebsiteAgentServiceTest extends TestCase
{
    public function testBuildFromDescriptionSkipsRegistrarFlowForLocalDomainWithoutAccount(): void
    {
        $purchaseService = $this->createMock(DomainPurchaseService::class);
        $purchaseService->expects($this->never())->method('createAndProcessOrder');

        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->never())->method('execute');

        $service = new WebsiteAgentService(
            $purchaseService,
            $this->createMock(DomainResolveService::class),
            $queryService
        );

        $result = $service->buildFromDescription(
            'Local Site',
            'weline-dev.weline.test',
            0
        );

        $this->assertTrue($result['success']);
        $this->assertSame('weline-dev.weline.test', $result['domain'] ?? '');
        $this->assertSame(0, $result['order_id'] ?? -1);
    }

    public function testRecommendAvailableDomainReturnsFirstAvailableCandidateInPriorityOrder(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->with(
                'websites',
                'checkAvailability',
                $this->callback(function (array $params): bool {
                    $this->assertSame(7, $params['account_id'] ?? null);
                    $this->assertSame('beanlane.com', $params['domains'][0] ?? null);
                    $this->assertTrue(
                        \in_array('beanlane.net', $params['domains'] ?? [], true)
                        || \in_array('beanlane.io', $params['domains'] ?? [], true)
                    );
                    return true;
                })
            )
            ->willReturn([
                ['domain' => 'beanlane.com', 'available' => false],
                ['domain' => 'beanlane.net', 'available' => true],
                ['domain' => 'coffee.com', 'available' => true],
            ]);

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Coffee brand storefront',
            7,
            'beanlane.com'
        );

        $this->assertTrue($result['success']);
        $this->assertContains($result['domain'] ?? '', ['beanlane.net', 'beanlane.io']);
        $this->assertSame('beanlane.com', $result['candidate_domains'][0] ?? '');
        $checkedResults = \is_array($result['checked_results'] ?? null) ? $result['checked_results'] : [];
        $this->assertTrue(
            \in_array('beanlane.net', \array_column($checkedResults, 'domain'), true)
            || \in_array('beanlane.io', \array_column($checkedResults, 'domain'), true)
        );
        $selectedResult = null;
        foreach ($checkedResults as $checkedResult) {
            if (!\is_array($checkedResult)) {
                continue;
            }
            if (($checkedResult['domain'] ?? '') === ($result['domain'] ?? '')) {
                $selectedResult = $checkedResult;
                break;
            }
        }
        $this->assertIsArray($selectedResult);
        $this->assertTrue((bool)($selectedResult['available'] ?? false));
    }

    public function testRecommendAvailableDomainReturnsFailureWhenNoCandidateIsAvailable(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->willReturn([
                ['domain' => 'atlaslab.com', 'available' => false],
                ['domain' => 'atlaslab.net', 'available' => false],
                ['domain' => 'atlaslab.site', 'available' => false, 'error' => 'registry timeout'],
            ]);

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Atlas lab marketing site',
            9,
            'atlaslab.com'
        );

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('domain', $result);
        $this->assertNotEmpty($result['candidate_domains']);
        $checkedResults = \is_array($result['checked_results'] ?? null) ? $result['checked_results'] : [];
        $this->assertGreaterThanOrEqual(5, \count($checkedResults));
        $siteResult = null;
        foreach ($checkedResults as $checkedResult) {
            if (!\is_array($checkedResult)) {
                continue;
            }
            if (($checkedResult['domain'] ?? '') === 'atlaslab.site') {
                $siteResult = $checkedResult;
                break;
            }
        }
        $this->assertIsArray($siteResult);
        $this->assertSame('registry timeout', $siteResult['error'] ?? '');
    }

    public function testRecommendAvailableDomainDeferSkipsRegistrarAvailabilityQuery(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->never())->method('execute');

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Coffee brand storefront',
            0,
            'beanlane.com',
            true
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['domain'] ?? '');
        $this->assertSame('beanlane.com', $result['candidate_domains'][0] ?? '');
        $this->assertSame([], $result['checked_results'] ?? null);
        $this->assertTrue(!empty($result['availability_deferred']));
    }

    public function testRecommendAvailableDomainDeferReturnsLocalRandomSubdomainForLocalAccount(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->never())->method('execute');

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Local demo storefront',
            900001,
            '',
            true
        );

        $this->assertTrue($result['success']);
        $domain = (string)($result['domain'] ?? '');
        $this->assertNotSame('', $domain);
        $this->assertTrue(\str_ends_with($domain, '.weline.test'));
        $this->assertSame([$domain], $result['candidate_domains'] ?? []);
        $this->assertSame([], $result['checked_results'] ?? null);
        $this->assertTrue(!empty($result['availability_deferred']));
        $this->assertTrue(!empty($result['simulated']));
    }

    public function testRecommendationCandidatesPreferBusinessAndMarketTokensForChineseBrief(): void
    {
        $service = $this->createService($this->createMock(FrameworkQueryService::class));
        $candidates = $service->getRecommendationCandidates(
            '我想做一个印度市场的棋牌网站，推广棋牌apk下载的seo网站',
            'apk'
        );

        $this->assertNotEmpty($candidates);
        $firstEight = \array_slice($candidates, 0, 8);
        $this->assertTrue(
            \array_reduce(
                $firstEight,
                static fn (bool $carry, string $domain): bool =>
                    $carry || \str_contains($domain, 'india')
                    || \str_contains($domain, 'desi')
                    || \str_contains($domain, 'rummy')
                    || \str_contains($domain, 'teenpatti')
                    || \str_contains($domain, 'cardgame'),
                false
            )
        );

        $firstTwelve = \array_slice($candidates, 0, 12);
        $longCount = 0;
        foreach ($firstTwelve as $domain) {
            $label = (string)(\explode('.', $domain)[0] ?? '');
            if (\strlen($label) >= 10) {
                $longCount++;
            }
        }
        $this->assertGreaterThanOrEqual(8, $longCount);
    }

    private function createService(FrameworkQueryService $queryService): WebsiteAgentService
    {
        return new WebsiteAgentService(
            $this->createMock(DomainPurchaseService::class),
            $this->createMock(DomainResolveService::class),
            $queryService
        );
    }
}
