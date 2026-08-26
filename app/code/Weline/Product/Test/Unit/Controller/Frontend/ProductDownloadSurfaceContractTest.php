<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductDownloadSurfaceContractTest extends TestCase
{
    public function testPaidObserverIsSynchronousCriticalAndReadsOrderUuid(): void
    {
        $root = dirname(__DIR__, 4);
        $event = (string)file_get_contents($root . '/etc/event.xml');
        $observer = (string)file_get_contents(
            $root . '/Observer/GrantDownloadEntitlementsOnOrderPaid.php',
        );

        self::assertStringContainsString('Weline_Order::order_paid', $event);
        self::assertStringContainsString('delivery="sync"', $event);
        self::assertStringContainsString('failure="critical"', $event);
        self::assertStringContainsString("getData('order_uuid')", $observer);
    }

    public function testDownloadRouteAndControllerAcceptOnlyEntitlementUuid(): void
    {
        $root = dirname(__DIR__, 4);
        $router = (string)file_get_contents($root . '/Controller/Router.php');
        $controller = (string)file_get_contents($root . '/Controller/Frontend/Download.php');

        self::assertStringContainsString('product-download/', $router);
        self::assertStringContainsString('input.query.entitlement_uuid', $router);
        self::assertStringContainsString("getParam('entitlement_uuid'", $controller);
        self::assertStringNotContainsString("getParam('asset_id'", $controller);
    }

    public function testCustomerQueryIsUncachedAndEntitlementGrantUsesFrozenOrderMetadata(): void
    {
        $root = dirname(__DIR__, 4);
        $query = (string)file_get_contents(
            $root . '/extends/module/Weline_Framework/Query/ProductDownloadQueryProvider.php',
        );
        $service = (string)file_get_contents(
            $root . '/Service/ProductDownloadEntitlementService.php',
        );

        self::assertStringContainsString("'auth' => 'customer'", $query);
        self::assertStringContainsString("'cache_ttl' => 0", $query);
        self::assertStringContainsString('OrderFacadeInterface', $service);
        self::assertStringContainsString("['fulfillment_metadata']['digital_download']", $service);
        self::assertStringContainsString('StorageUrlOptions::KIND_TEMPORARY', $service);
        self::assertStringContainsString("additional('FOR UPDATE')", $service);
    }
}
