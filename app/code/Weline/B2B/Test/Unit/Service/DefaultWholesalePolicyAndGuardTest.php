<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\DefaultWholesalePolicy;
use Weline\B2B\Service\WholesalePricingGuard;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require_once dirname(__DIR__) . '/bootstrap.php';

final class DefaultWholesalePolicyAndGuardTest extends TestCase
{
    public function testSeedFormulaCapsAtMaxDiscount(): void
    {
        $rows = DefaultWholesalePolicy::seedTierRows(2000);
        self::assertCount(13, $rows);
        self::assertSame(5, $rows[0]['min_qty']);
        self::assertSame(500, $rows[0]['discount_bps']);
        self::assertSame(2000, $rows[3]['discount_bps']);
        self::assertSame(2000, $rows[12]['discount_bps']);
        self::assertSame(5 + 12 * 5, $rows[12]['min_qty']);
    }

    public function testMaterializeAmountFloorsAndGuardRejectsOverDiscount(): void
    {
        $policy = DefaultWholesalePolicy::forTesting(2000);
        $amount = $policy->materializeAmount(10000, 500);
        self::assertSame(9500, $amount);

        $guard = new WholesalePricingGuard();
        $guard->assertAmountAllowed(10000, 8000, 2000);
        $this->expectException(\InvalidArgumentException::class);
        $guard->assertAmountAllowed(10000, 7999, 2000);
    }

    public function testAssertAndNormalizeForSaveRejectsOverMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DefaultWholesalePolicy::assertAndNormalizeForSave([
            ['tier_rank' => 0, 'min_qty' => 5, 'discount_bps' => 2500],
        ], 2000);
    }

    public function testEngineInheritsDefaultPolicyWhenTobEnabledAndNoList(): void
    {
        $policy = DefaultWholesalePolicy::forTesting(2000);
        $engine = B2BPriceEngine::forTesting(null, $policy, new WholesalePricingGuard());
        $engine->rollout()->setMode(B2BPriceEngine::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        $service = new B2BService(
            $engine,
            $engine->rollout(),
            \Weline\B2B\Service\B2BCheckoutRecheckService::forTesting($engine),
        );
        $service->seedGroup(SystemVipLadder::groupId(0), 0, SystemVipLadder::code(0));
        $service->assignCustomer('cust-vip0', SystemVipLadder::groupId(0));

        $hit = $service->resolve([
            'customer_id' => 'cust-vip0',
            'website_id' => 0,
            'sku' => 'SKU-INHERIT',
            'qty' => 5,
            'retail_amount_minor' => 10000,
            'selling_mode_tob' => true,
        ]);
        self::assertTrue($hit['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_B2B_DEFAULT_POLICY, $hit['source']);
        self::assertSame(9500, $hit['amount_minor']);
        self::assertSame(DefaultWholesalePolicy::syntheticListId(0, SystemVipLadder::groupId(0)), $hit['price_list_id']);

        $blocked = $service->resolve([
            'customer_id' => 'cust-vip0',
            'website_id' => 0,
            'sku' => 'SKU-INHERIT',
            'qty' => 5,
            'retail_amount_minor' => 10000,
            'selling_mode_tob' => false,
        ]);
        self::assertTrue($blocked['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_RETAIL, $blocked['source']);
        self::assertSame(10000, $blocked['amount_minor']);
    }

    public function testExplicitListBeatsDefaultPolicy(): void
    {
        $policy = DefaultWholesalePolicy::forTesting(2000);
        $engine = B2BPriceEngine::forTesting(null, $policy, new WholesalePricingGuard());
        $engine->rollout()->setMode(B2BPriceEngine::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
        $service = new B2BService(
            $engine,
            $engine->rollout(),
            \Weline\B2B\Service\B2BCheckoutRecheckService::forTesting($engine),
        );
        $gid = SystemVipLadder::groupId(0);
        $service->seedGroup($gid, 0, SystemVipLadder::code(0));
        $service->assignCustomer('cust-vip0', $gid);
        $service->seedPriceList('pl-override', $gid, 0, 1, [
            'SKU-X' => [1 => 8800],
        ]);

        $hit = $service->resolve([
            'customer_id' => 'cust-vip0',
            'website_id' => 0,
            'sku' => 'SKU-X',
            'qty' => 1,
            'retail_amount_minor' => 10000,
            'selling_mode_tob' => true,
        ]);
        self::assertTrue($hit['ok']);
        self::assertSame(B2BPriceEngine::SOURCE_B2B_WEBSITE, $hit['source']);
        self::assertSame(8800, $hit['amount_minor']);
    }

    public function testNonVipGroupDoesNotInheritTemplate(): void
    {
        $policy = DefaultWholesalePolicy::forTesting();
        self::assertFalse($policy->groupCanInheritTemplate('g-custom-dealer'));
        self::assertSame([], $policy->tiersForGroup('g-custom-dealer', 0));
    }
}
