<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;

/**
 * Contract: 万能壳契约去供应商语义；Webhook 标准 DTO；URL 用 provider_code 切换。
 */
final class UniversalShellContractTest extends TestCase
{
    public function testWebhookInterfaceDeclaresVerifyAndDocsStandardDto(): void
    {
        self::assertTrue(method_exists(DropshipWebhookProviderInterface::class, 'verifyWebhook'));
        $ref = new \ReflectionMethod(DropshipWebhookProviderInterface::class, 'parseWebhook');
        $doc = (string)$ref->getDocComment();
        self::assertStringContainsString('external_order_id', $doc);
        self::assertStringContainsString('fulfillment', $doc);
        self::assertStringContainsString('topic', $doc);
        self::assertStringContainsString('catalog', $doc);
        self::assertStringContainsString('webhook_order', $doc);
    }

    public function testShellServicesDoNotReadCjPayloadKeys(): void
    {
        $files = [
            dirname(__DIR__, 3) . '/Service/DropshipWebhookInboxService.php',
            dirname(__DIR__, 3) . '/Service/DropshipOutboxService.php',
            dirname(__DIR__, 3) . '/Service/DropshipPublishService.php',
            dirname(__DIR__, 3) . '/Controller/Backend/Warehouse.php',
            dirname(__DIR__, 3) . '/Controller/Backend/Listing.php',
            dirname(__DIR__, 3) . '/Controller/Backend/Channel.php',
        ];
        $forbidden = [
            'cj_country_code',
            'cj_storage_id',
            "getGet('provider', 'cj')",
            'cj_credentials_missing',
            'cj_probe_ok',
            "['orderId']",
            "['trackNumber']",
            "['logisticName']",
            "['orderStatus']",
        ];
        $files[] = dirname(__DIR__, 3) . '/Service/DropshipWarehouseMapService.php';
        foreach ($files as $file) {
            self::assertFileExists($file);
            $src = (string)file_get_contents($file);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $src,
                    basename($file) . ' must not embed vendor shell keys (' . $needle . ')'
                );
            }
        }
    }

    public function testInboxServiceReparsesEmptyFulfillmentViaProviderRawBody(): void
    {
        $file = dirname(__DIR__, 3) . '/Service/DropshipWebhookInboxService.php';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('fulfillmentNeedsReparse', $src);
        self::assertStringContainsString('reparseEnvelope', $src);
        self::assertStringContainsString("envelope['raw_body']", $src);
        self::assertStringContainsString('DropshipWebhookProviderInterface', $src);
        self::assertStringContainsString('parseWebhook', $src);
        self::assertStringContainsString('enqueueCatalogFollow', $src);
        self::assertStringNotContainsString('cjOrderId', $src);
    }

    public function testWarehouseTemplateUsesGenericRemoteFields(): void
    {
        $tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Warehouse/index.phtml');
        self::assertStringContainsString('country-name="remote_country_code"', $tpl);
        self::assertStringContainsString('name="remote_storage_id"', $tpl);
        self::assertStringNotContainsString('cj_country_code', $tpl);
        self::assertStringNotContainsString('cj_storage_id', $tpl);
    }

    public function testWarehouseModelUsesGenericColumns(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/DropshipScopeWarehouseMap.php');
        self::assertStringContainsString("'remote_country_code'", $src);
        self::assertStringContainsString("'remote_storage_id'", $src);
        self::assertStringNotContainsString("'cj_country_code'", $src);
        self::assertStringNotContainsString("'cj_storage_id'", $src);
    }
}
