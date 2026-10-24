<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider\FakeDropshipProvider;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Dropship\Service\DropshipProviderControllerDispatcher;
use Weline\Dropship\Service\DropshipProviderControllerRouteScanner;
use Weline\Dropship\Service\DropshipWarehouseMapService;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllDropshipArchitectureContractsPass().
 */
final class DropshipArchitectureContractsTest extends TestCase
{
    public function testAllDropshipArchitectureContractsPass(): void
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
            'NoLegacyAndProviderGateway::testWarehouseMapServiceHasNoCjFallback' => function () {
            $this->assert_NoLegacyAndProviderGateway_testWarehouseMapServiceHasNoCjFallback();
        },
            'NoLegacyAndProviderGateway::testGatewayForbiddenActionsAndFakeControllerPing' => function () {
            $this->assert_NoLegacyAndProviderGateway_testGatewayForbiddenActionsAndFakeControllerPing();
        },
            'NoLegacyAndProviderGateway::testShellHasProviderGatewayControllers' => function () {
            $this->assert_NoLegacyAndProviderGateway_testShellHasProviderGatewayControllers();
        },
            'NoLegacyAndProviderGateway::testUpgradeMigratesAndDropsLegacyColumns' => function () {
            $this->assert_NoLegacyAndProviderGateway_testUpgradeMigratesAndDropsLegacyColumns();
        },
            'ShellProviderIsomorph::testBackendControllersDoNotEmbedVendorClientsOrApis' => function () {
            $this->assert_ShellProviderIsomorph_testBackendControllersDoNotEmbedVendorClientsOrApis();
        },
            'ShellProviderIsomorph::testProviderDevelopmentDocumentsIsomorphHardRule' => function () {
            $this->assert_ShellProviderIsomorph_testProviderDevelopmentDocumentsIsomorphHardRule();
        },
            'UniversalShell::testWebhookInterfaceDeclaresVerifyAndDocsStandardDto' => function () {
            $this->assert_UniversalShell_testWebhookInterfaceDeclaresVerifyAndDocsStandardDto();
        },
            'UniversalShell::testShellServicesDoNotReadCjPayloadKeys' => function () {
            $this->assert_UniversalShell_testShellServicesDoNotReadCjPayloadKeys();
        },
            'UniversalShell::testInboxServiceReparsesEmptyFulfillmentViaProviderRawBody' => function () {
            $this->assert_UniversalShell_testInboxServiceReparsesEmptyFulfillmentViaProviderRawBody();
        },
            'UniversalShell::testWarehouseTemplateUsesGenericRemoteFields' => function () {
            $this->assert_UniversalShell_testWarehouseTemplateUsesGenericRemoteFields();
        },
            'UniversalShell::testWarehouseModelUsesGenericColumns' => function () {
            $this->assert_UniversalShell_testWarehouseModelUsesGenericColumns();
        },
            'WebhookCapabilityMatrix::testCjKeepsAllWebhookCapabilities' => function () {
            $this->assert_WebhookCapabilityMatrix_testCjKeepsAllWebhookCapabilities();
        },
            'WebhookCapabilityMatrix::testDocsMarkSandboxDisputeUnsupportedAndShellKept' => function () {
            $this->assert_WebhookCapabilityMatrix_testDocsMarkSandboxDisputeUnsupportedAndShellKept();
        },
        ];
    }

private function assert_NoLegacyAndProviderGateway_testWarehouseMapServiceHasNoCjFallback(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWarehouseMapService.php');
    self::assertStringNotContainsString('cj_country_code', $src);
    self::assertStringNotContainsString('cj_storage_id', $src);
    self::assertSame('', DropshipWarehouseMapService::remoteCountryCode([]));
    self::assertSame('US', DropshipWarehouseMapService::remoteCountryCode(['remote_country_code' => 'US']));
}

private function assert_NoLegacyAndProviderGateway_testGatewayForbiddenActionsAndFakeControllerPing(): void
{
    self::assertContains('notify', DropshipProviderControllerDispatcher::FORBIDDEN_ACTIONS);
    self::assertContains('controllerNotify', DropshipProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
    $fake = new FakeDropshipProvider();
    self::assertTrue(method_exists($fake, 'controllerPing'));
    $ping = $fake->controllerPing([]);
    self::assertTrue($ping['ok']);
    self::assertSame('fake', $ping['provider']);
}

private function assert_NoLegacyAndProviderGateway_testShellHasProviderGatewayControllers(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    self::assertFileExists($root . '/Controller/Frontend/ProviderGateway.php');
    self::assertFileExists($root . '/Controller/Backend/ProviderGateway.php');
    $front = (string) file_get_contents($root . '/Controller/Frontend/ProviderGateway.php');
    self::assertStringContainsString('DropshipProviderControllerDispatcher', $front);
    self::assertStringContainsString('provider_code', $front);
}

private function assert_NoLegacyAndProviderGateway_testUpgradeMigratesAndDropsLegacyColumns(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Setup/Upgrade.php');
    self::assertStringContainsString('remote_country_code', $src);
    self::assertStringContainsString('cj_country_code', $src);
    self::assertStringContainsString('cj_storage_id', $src);
    // RSSE owns schema: legacy columns are no longer dropped by the setup hook.
    self::assertStringNotContainsString('DROP COLUMN IF EXISTS', $src);
}

private function assert_ShellProviderIsomorph_testBackendControllersDoNotEmbedVendorClientsOrApis(): void
{
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend';
    self::assertDirectoryExists($dir);
    $files = glob($dir . '/*.php') ?: [];
    self::assertNotEmpty($files);
    $forbidden = ['CjApiClient', 'Weline\CjDropshipping', 'curl_exec', "file_get_contents('https://"];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $src, basename($file) . ' must not embed vendor business (' . $needle . '); put it in Extends DropshipProvider.');
        }
    }
}

private function assert_ShellProviderIsomorph_testProviderDevelopmentDocumentsIsomorphHardRule(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/doc';
    $provider = (string) file_get_contents($root . '/provider-development.md');
    $shell = (string) file_get_contents($root . '/dropship-shell.md');
    self::assertStringContainsString('shell_provider_business_isomorph', $provider);
    self::assertStringContainsString('硬性同构', $provider);
    self::assertStringContainsString('禁止', $provider);
    self::assertStringContainsString('shell_provider_business_isomorph', $shell);
    self::assertStringContainsString('Provider 必须拥有', $shell);
}

private function assert_UniversalShell_testWebhookInterfaceDeclaresVerifyAndDocsStandardDto(): void
{
    self::assertTrue(method_exists(DropshipWebhookProviderInterface::class, 'verifyWebhook'));
    $ref = new \ReflectionMethod(DropshipWebhookProviderInterface::class, 'parseWebhook');
    $doc = (string) $ref->getDocComment();
    self::assertStringContainsString('external_order_id', $doc);
    self::assertStringContainsString('fulfillment', $doc);
    self::assertStringContainsString('topic', $doc);
    self::assertStringContainsString('catalog', $doc);
    self::assertStringContainsString('webhook_order', $doc);
}

private function assert_UniversalShell_testShellServicesDoNotReadCjPayloadKeys(): void
{
    $files = ['/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWebhookInboxService.php', '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipOutboxService.php', '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipPublishService.php', '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend/Warehouse.php', '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend/Listing.php', '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend/Channel.php'];
    $forbidden = ['cj_country_code', 'cj_storage_id', "getGet('provider', 'cj')", 'cj_credentials_missing', 'cj_probe_ok', "['orderId']", "['trackNumber']", "['logisticName']", "['orderStatus']"];
    $files[] = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWarehouseMapService.php';
    foreach ($files as $file) {
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $src, basename($file) . ' must not embed vendor shell keys (' . $needle . ')');
        }
    }
}

private function assert_UniversalShell_testInboxServiceReparsesEmptyFulfillmentViaProviderRawBody(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWebhookInboxService.php';
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('fulfillmentNeedsReparse', $src);
    self::assertStringContainsString('reparseEnvelope', $src);
    self::assertStringContainsString("envelope['raw_body']", $src);
    self::assertStringContainsString('DropshipWebhookProviderInterface', $src);
    self::assertStringContainsString('parseWebhook', $src);
    self::assertStringContainsString('enqueueCatalogFollow', $src);
    self::assertStringNotContainsString('cjOrderId', $src);
}

private function assert_UniversalShell_testWarehouseTemplateUsesGenericRemoteFields(): void
{
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/view/templates/backend/Warehouse/index.phtml');
    self::assertStringContainsString('country-name="remote_country_code"', $tpl);
    self::assertStringContainsString('name="remote_storage_id"', $tpl);
    self::assertStringNotContainsString('cj_country_code', $tpl);
    self::assertStringNotContainsString('cj_storage_id', $tpl);
}

private function assert_UniversalShell_testWarehouseModelUsesGenericColumns(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Model/DropshipScopeWarehouseMap.php');
    self::assertStringContainsString("'remote_country_code'", $src);
    self::assertStringContainsString("'remote_storage_id'", $src);
    self::assertStringNotContainsString("'cj_country_code'", $src);
    self::assertStringNotContainsString("'cj_storage_id'", $src);
}

private function assert_WebhookCapabilityMatrix_testCjKeepsAllWebhookCapabilities(): void
{
    $caps = (new CjProvider())->getCapabilities();
    self::assertTrue(!empty($caps['webhook']));
    foreach (['webhook_order', 'webhook_product', 'webhook_stock', 'webhook_logistics', 'webhook_makeup', 'webhook_private_order', 'webhook_dispute'] as $key) {
        self::assertTrue(!empty($caps[$key]), $key . ' must stay enabled on shell/provider');
    }
}

private function assert_WebhookCapabilityMatrix_testDocsMarkSandboxDisputeUnsupportedAndShellKept(): void
{
    $dropship = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/doc/功能现状.md');
    $cj = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/CjDropshipping/doc/功能现状.md');
    $req = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/CjDropshipping/doc/需求.md');
    $shell = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/doc/dropship-shell.md');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/doc/provider-development.md');
    foreach ([$dropship, $cj, $shell, $provider] as $doc) {
        self::assertStringContainsString('壳已接', $doc);
        self::assertStringContainsString('沙盒真推', $doc);
        self::assertStringContainsString('沙盒不可用', $doc);
    }
    self::assertStringContainsString('webhook_dispute', $dropship . $cj . $provider);
    self::assertStringContainsString('disputes/create', $cj . $req);
    self::assertStringContainsString('Sandbox account is not supported', $cj . $req . $dropship);
}
}
