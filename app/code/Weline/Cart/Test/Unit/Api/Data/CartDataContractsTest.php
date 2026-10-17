<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Api\Data;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCartDataContractsPass().
 */
final class CartDataContractsTest extends TestCase
{
    public function testAllCartDataContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CartItemSnapshotDealChrome::testToArrayExposesCompareAtAndCampaign' => function () {
            $this->assert_CartItemSnapshotDealChrome_testToArrayExposesCompareAtAndCampaign();
        },
            'CartItemSnapshotDealChrome::testHasDealFalseWhenCompareAtNotHigher' => function () {
            $this->assert_CartItemSnapshotDealChrome_testHasDealFalseWhenCompareAtNotHigher();
        },
        ];
    }

private function assert_CartItemSnapshotDealChrome_testToArrayExposesCompareAtAndCampaign(): void
{
    $snapshot = new CartItemSnapshot(offer: new OfferIdentity('product', 'offer-deal-1', 42), name: 'Student Hanfu', unitPriceMinor: 8010, compareAtMinor: 8900, campaignLabel: "Today's Picks", campaignUrl: '/promotion/deals');
    $row = $snapshot->toArray();
    self::assertSame(8010, $row['unit_price_minor']);
    self::assertSame(8900, $row['compare_at_minor']);
    self::assertSame(80.09999999999999, $row['price']);
    self::assertSame(89.0, $row['original_price']);
    self::assertTrue($row['has_deal']);
    self::assertSame("Today's Picks", $row['campaign_label']);
    self::assertSame('/promotion/deals', $row['campaign_url']);
}

private function assert_CartItemSnapshotDealChrome_testHasDealFalseWhenCompareAtNotHigher(): void
{
    $snapshot = new CartItemSnapshot(offer: new OfferIdentity('product', 'offer-plain-1', 7), name: 'Plain', unitPriceMinor: 5000, compareAtMinor: 5000, campaignLabel: 'Ignored');
    $row = $snapshot->toArray();
    self::assertFalse($row['has_deal']);
    self::assertSame(5000, $row['compare_at_minor']);
}
}
