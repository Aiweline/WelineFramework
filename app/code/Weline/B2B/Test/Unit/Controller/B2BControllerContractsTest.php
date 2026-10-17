<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\B2B\Controller\Backend\ControlCenter;
use Weline\Framework\Acl\Acl;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllB2BControllerContractsPass().
 */
final class B2BControllerContractsTest extends TestCase
{
    public function testAllB2BControllerContractsPass(): void
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
            'B2BBackendScopeFilter::testFilterRowsByB2bScopeMatchesWebsiteAndChannelInheritance' => function () {
            $this->assert_B2BBackendScopeFilter_testFilterRowsByB2bScopeMatchesWebsiteAndChannelInheritance();
        },
            'ControlCenterDeleteAcl::testDeleteMembershipApplicationHasDedicatedAclSource' => function () {
            $this->assert_ControlCenterDeleteAcl_testDeleteMembershipApplicationHasDedicatedAclSource();
        },
            'SaveProductSkuTiersRoute::testControlCenterDeclaresSaveProductSkuTiersAction' => function () {
            $this->assert_SaveProductSkuTiersRoute_testControlCenterDeclaresSaveProductSkuTiersAction();
        },
            'SaveProductSkuTiersRoute::testPriceListFormKeepsAmountMinorAndAddsMinQty' => function () {
            $this->assert_SaveProductSkuTiersRoute_testPriceListFormKeepsAmountMinorAndAddsMinQty();
        },
            'SaveProductSkuTiersRoute::testProductHooksUseAbsoluteB2bBackendUrls' => function () {
            $this->assert_SaveProductSkuTiersRoute_testProductHooksUseAbsoluteB2bBackendUrls();
        },
        ];
    }

private function assert_B2BBackendScopeFilter_testFilterRowsByB2bScopeMatchesWebsiteAndChannelInheritance(): void
{
    $method = (new ReflectionClass(ControlCenter::class))->getMethod('filterRowsByB2bScope');
    $method->setAccessible(true);
    $controller = (new ReflectionClass(ControlCenter::class))->newInstanceWithoutConstructor();
    $rows = [['website_id' => 0, 'channel_id' => null, 'sku' => 'a'], ['website_id' => 0, 'channel_id' => 'ch-a', 'sku' => 'b'], ['website_id' => 1, 'channel_id' => 'ch-a', 'sku' => 'c'], ['website_id' => 0, 'channel_id' => 'ch-b', 'sku' => 'd']];
    $websiteOnly = $method->invoke($controller, $rows, 0, null);
    self::assertSame(['a', 'b', 'd'], array_column($websiteOnly, 'sku'));
    $channel = $method->invoke($controller, $rows, 0, 'ch-a');
    self::assertSame(['a', 'b'], array_column($channel, 'sku'));
    $all = $method->invoke($controller, $rows, null, null);
    self::assertCount(4, $all);
}

private function assert_ControlCenterDeleteAcl_testDeleteMembershipApplicationHasDedicatedAclSource(): void
{
    $method = (new ReflectionClass(ControlCenter::class))->getMethod('removeMembershipApplication');
    $attrs = $method->getAttributes(Acl::class);
    self::assertNotEmpty($attrs);
    $acl = $attrs[0]->newInstance();
    self::assertSame('Weline_B2B::commerce:partner:applications:delete', $acl->getData('source_id'));
}

private function assert_SaveProductSkuTiersRoute_testControlCenterDeclaresSaveProductSkuTiersAction(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Controller/Backend/ControlCenter.php';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('function saveProductSkuTiers', $src);
    self::assertStringContainsString('upsertSkuQtyTiers', $src);
    self::assertStringContainsString("Weline_B2B::commerce:partner:price-lists", $src);
}

private function assert_SaveProductSkuTiersRoute_testPriceListFormKeepsAmountMinorAndAddsMinQty(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/backend/ControlCenter/index.phtml';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('name="amount_minor"', $src);
    self::assertStringContainsString('name="min_qty"', $src);
    self::assertStringContainsString('name="tiers[1][min_qty]"', $src);
    self::assertStringContainsString('prefillSku', $src);
}

private function assert_SaveProductSkuTiersRoute_testProductHooksUseAbsoluteB2bBackendUrls(): void
{
    $hooks = ['/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Product/backend/catalog/edit/basic-after.phtml', '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/hooks/Weline_Product/backend/catalog/edit/offers-after.phtml'];
    foreach ($hooks as $file) {
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertStringNotContainsString("getUrl('*/b2b/", $src, $file);
        self::assertStringNotContainsString('getUrl("*/b2b/', $src, $file);
        self::assertStringContainsString("getBackendUrl(", $src, $file);
        self::assertStringContainsString('b2b/backend/control-center/price-lists', $src, $file);
    }
    $offers = (string) file_get_contents($hooks[1]);
    self::assertStringContainsString('b2b/backend/control-center/save-product-sku-tiers', $offers);
}
}
