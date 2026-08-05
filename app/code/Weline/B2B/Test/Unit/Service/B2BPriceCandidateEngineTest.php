<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\B2BShadowComparator;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TEST-P4C-01 / TEST-P4C-02（候选层）：合法组价目、伪造价目拒绝、mode off、shadow。
 */
final class B2BPriceCandidateEngineTest extends TestCase
{
    private B2BService $service;

    protected function setUp(): void
    {
        $this->service = B2BService::forTesting();
        $this->service->seedGroup('g-dealer', 0, 'dealer');
        $this->service->assignCustomer('cust-b2b', 'g-dealer');
        $this->service->seedPriceList('pl-dealer-v1', 'g-dealer', 0, 1, [
            'SKU-A' => 800,
            'SKU-B' => 1200,
        ]);
        $this->service->seedPriceList('pl-dealer-ch', 'g-dealer', 0, 2, [
            'SKU-A' => 750,
        ], 'ch-pro');
    }

    public function testModeOffClosesB2bCandidate(): void
    {
        $result = $this->service->resolve([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_CLOSED, $result['source']);
        self::assertSame(1000, $result['amount_minor']);
        self::assertNull($result['price_list_id']);
        self::assertContains(B2BPriceEngine::ERROR_MODE_OFF, $result['rule_stack']);
    }

    public function testB2bCustomerGetsPriceListWhileRetailKeepsRetail(): void
    {
        $this->service->enableShadow();

        $b2b = $this->service->resolve([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($b2b['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_B2B_WEBSITE, $b2b['source']);
        self::assertSame(800, $b2b['amount_minor']);
        self::assertSame('pl-dealer-v1', $b2b['price_list_id']);
        self::assertSame(1, $b2b['version']);

        $retail = $this->service->resolve([
            'customer_id' => 'cust-retail',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($retail['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_RETAIL, $retail['source']);
        self::assertSame(1000, $retail['amount_minor']);
        self::assertNull($retail['price_list_id']);
    }

    public function testChannelOverrideBeatsWebsiteList(): void
    {
        $this->service->enableShadow();
        $result = $this->service->resolve([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'channel_id' => 'ch-pro',
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_B2B_CHANNEL, $result['source']);
        self::assertSame(750, $result['amount_minor']);
        self::assertSame('pl-dealer-ch', $result['price_list_id']);
        self::assertSame(2, $result['version']);
    }

    public function testRetailForgedPriceListIdIsRejectedWithZeroOrders(): void
    {
        $this->service->enableShadow();
        $before = $this->service->engine()->orderCount();

        $result = $this->service->resolve([
            'customer_id' => 'cust-retail',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
            'claimed_price_list_id' => 'pl-dealer-v1',
            'claimed_version' => 1,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(B2BPriceEngine::ERROR_FORGED_PRICE_LIST, $result['error']);
        self::assertSame($before, $this->service->engine()->orderCount());
        self::assertSame([], $this->service->engine()->orderAttempts());
    }

    public function testRequestedGroupCannotOverrideServerMembership(): void
    {
        $this->service->enableShadow();
        $before = $this->service->engine()->orderCount();

        $result = $this->service->resolve([
            'customer_id' => 'cust-retail',
            'group_id' => 'g-dealer',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(B2BPriceEngine::ERROR_GROUP_OVERRIDE, $result['error']);
        self::assertNull($result['price_list_id']);
        self::assertSame($before, $this->service->engine()->orderCount());
    }

    public function testMembershipIsWebsiteScopedAndLatestRevisionWins(): void
    {
        $this->service->enableShadow();
        $this->service->seedPriceList('pl-dealer-v1', 'g-dealer', 0, 2, [
            'SKU-A' => 700,
        ]);

        $latest = $this->service->resolve([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($latest['ok']);
        self::assertSame(2, $latest['version']);
        self::assertSame(700, $latest['amount_minor']);

        $otherWebsite = $this->service->resolve([
            'customer_id' => 'cust-b2b',
            'website_id' => 1,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
            'claimed_price_list_id' => 'pl-dealer-v1',
            'claimed_version' => 2,
        ]);
        self::assertFalse($otherWebsite['ok']);
        self::assertSame(B2BPriceEngine::ERROR_FORGED_PRICE_LIST, $otherWebsite['error']);
        self::assertSame(0, $this->service->engine()->orderCount());
    }

    public function testDisabledGroupCannotUsePriceList(): void
    {
        $this->service->enableShadow();
        $this->service->seedGroup('g-dead', 0, 'dead', CustomerGroup::STATUS_DISABLED);
        $this->service->assignCustomer('cust-dead', 'g-dead');

        $result = $this->service->resolve([
            'customer_id' => 'cust-dead',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(B2BPriceEngine::ERROR_GROUP_DISABLED, $result['error']);
    }

    public function testZeroDiffShadowCompare(): void
    {
        $svc = B2BService::forTesting();
        $svc->enableShadow();
        $svc->seedGroup('g1', 0, 'g1');
        $svc->assignCustomer('c1', 'g1');
        $svc->seedPriceList('pl1', 'g1', 0, 1, ['SKU-A' => 500]);

        $twin = B2BService::forTesting();
        $twin->enableShadow();
        $twin->seedGroup('g1', 0, 'g1');
        $twin->assignCustomer('c1', 'g1');
        $twin->seedPriceList('pl1', 'g1', 0, 1, ['SKU-A' => 500]);
        $cmp = new B2BShadowComparator($svc->engine(), $twin->engine());

        $report = $cmp->compare([
            'customer_id' => 'c1',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 999,
        ]);
        self::assertTrue($report['ok']);
        self::assertSame(0, $report['unclassified_diff_count']);
    }
}
