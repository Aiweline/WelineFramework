<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Service\Query\FrameworkQueryService;

class QuickBuildAggregatorPurchaseDomainTest extends TestCase
{
    public function testPurchaseDomainPassesLifecycleOptionsToQueryProvider(): void
    {
        $eventsManager = $this->createMock(EventsManager::class);
        $queryService = $this->createMock(FrameworkQueryService::class);

        $queryService->expects($this->once())
            ->method('execute')
            ->with(
                'websites',
                'purchaseDomain',
                [
                    'account_id' => 12,
                    'items' => [['domain' => 'example.com', 'years' => 1]],
                    'auto_resolve' => true,
                    'resolve_to_local' => 'yes',
                    'subdomains' => ['@', 'www'],
                    'dns_choice' => 'custom_dns',
                    'dns_provider' => 'cloudflare',
                    'dns_account_id' => 21,
                    'dns_nameservers' => 'ns1.example.com,ns2.example.com',
                    'cdn_choice' => 'custom_cdn',
                    'cdn_provider' => 'cloudflare',
                    'cdn_account_id' => 22,
                    'start_lifecycle' => '1',
                ]
            )
            ->willReturn(['success' => true]);

        $aggregator = new QuickBuildAggregator($eventsManager, $queryService);

        $result = $aggregator->purchaseDomain(12, [['domain' => 'example.com', 'years' => 1]], true, [
            'resolve_to_local' => 'yes',
            'subdomains' => ['@', 'www'],
            'dns_choice' => 'custom_dns',
            'dns_provider' => 'cloudflare',
            'dns_account_id' => 21,
            'dns_nameservers' => 'ns1.example.com,ns2.example.com',
            'cdn_choice' => 'custom_cdn',
            'cdn_provider' => 'cloudflare',
            'cdn_account_id' => 22,
            'start_lifecycle' => '1',
        ]);

        $this->assertTrue($result['success']);
    }
}
