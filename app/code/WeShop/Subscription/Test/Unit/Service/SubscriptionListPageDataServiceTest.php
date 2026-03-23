<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Subscription\Service\SubscriptionListPageDataService;
use WeShop\Subscription\Service\SubscriptionService;
use Weline\Framework\Http\Url;

class SubscriptionListPageDataServiceTest extends TestCase
{
    public function testBuildNormalizesSubscriptionsAndActions(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())
            ->method('getCustomerSubscriptions')
            ->with(8, 2, 10, ['status' => 'active'])
            ->willReturn([
                'items' => [[
                    'subscription_id' => 18,
                    'status' => 'active',
                    'price' => '29.90',
                    'currency' => 'USD',
                    'billing_cycle' => 'month',
                    'billing_interval' => 1,
                    'current_period_start' => '2026-03-01 00:00:00',
                    'current_period_end' => '2026-03-31 23:59:59',
                    'next_billing_at' => '2026-04-01 00:00:00',
                    'renewal_count' => 4,
                    'created_at' => '2026-01-01 00:00:00',
                ]],
                'total' => 15,
                'pagination' => ['current' => 2],
            ]);

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturnMap([
            ['subscription', null, '/subscription'],
            ['subscription/view', null, '/subscription/view'],
            ['subscription/pause', null, '/subscription/pause'],
            ['subscription/pause/resume', null, '/subscription/pause/resume'],
            ['subscription/cancel', null, '/subscription/cancel'],
        ]);

        $service = new SubscriptionListPageDataService($subscriptionService, $url);
        $result = $service->build(8, 2, 10, 'active');

        $this->assertSame(15, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(2, $result['page_count']);
        $this->assertTrue($result['has_previous']);
        $this->assertFalse($result['has_next']);
        $this->assertSame('/subscription', $result['list_url']);
        $this->assertSame('/subscription/view', $result['view_url']);
        $this->assertCount(1, $result['items']);
        $this->assertSame(18, $result['items'][0]['subscription_id']);
        $this->assertTrue($result['items'][0]['can_pause']);
        $this->assertFalse($result['items'][0]['can_resume']);
        $this->assertTrue($result['items'][0]['can_cancel']);
        $this->assertNotEmpty($result['items'][0]['billing_label']);
    }

    public function testBuildHandlesPausedStatusPermissions(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects($this->once())
            ->method('getCustomerSubscriptions')
            ->with(9, 1, 10, [])
            ->willReturn([
                'items' => [[
                    'subscription_id' => 99,
                    'status' => 'paused',
                    'billing_cycle' => 'month',
                    'billing_interval' => 2,
                ]],
                'total' => 1,
                'pagination' => [],
            ]);

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturn('/x');

        $service = new SubscriptionListPageDataService($subscriptionService, $url);
        $result = $service->build(9, 1, 10, '');

        $this->assertCount(1, $result['items']);
        $this->assertFalse($result['items'][0]['can_pause']);
        $this->assertTrue($result['items'][0]['can_resume']);
        $this->assertTrue($result['items'][0]['can_cancel']);
        $this->assertStringContainsString('2', (string) $result['items'][0]['billing_label']);
    }
}
