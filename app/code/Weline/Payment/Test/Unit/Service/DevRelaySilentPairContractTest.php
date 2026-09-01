<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\DevRelayGateService;
use Weline\Payment\Service\DevRelayPairService;
use Weline\Payment\Service\DevRelaySessionService;

final class DevRelaySilentPairContractTest extends TestCase
{
    public function testGateExposesOnlineBaseUrlConfigKey(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Service/DevRelayGateService.php');
        self::assertIsString($src);
        self::assertStringContainsString("'online_base_url'", $src);
        self::assertStringContainsString('allow_on_production', $src);
    }

    public function testSessionServiceDefinesPublicStreamHelpers(): void
    {
        self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicStreamUrl'));
        self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicEventFetchUrl'));
        self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicAckUrl'));
        self::assertTrue(method_exists(DevRelaySessionService::class, 'buildPublicPairUrl'));
    }

    public function testPairServiceApiSurface(): void
    {
        self::assertTrue(method_exists(DevRelayPairService::class, 'pairFromRequest'));
        self::assertTrue(method_exists(DevRelayPairService::class, 'closeFromRequest'));
        self::assertTrue(method_exists(DevRelayPairService::class, 'ensureUserApiToken'));
        self::assertTrue(method_exists(DevRelayPairService::class, 'extractBearerToken'));
    }

    public function testFrontendDevRelayExposesSilentEndpoints(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/DevRelay.php');
        self::assertIsString($src);
        self::assertStringContainsString('function pair(', $src);
        self::assertStringContainsString('function stream(', $src);
        self::assertStringContainsString('function event(', $src);
        self::assertStringContainsString('function ack(', $src);
        self::assertStringContainsString('function updateInbound(', $src);
        self::assertStringContainsString('function workerStatus(', $src);
        self::assertStringContainsString('function workerStart(', $src);
        self::assertStringContainsString('function workerStop(', $src);
        self::assertStringContainsString('function panelProbe(', $src);
        self::assertStringContainsString('function demo(', $src);
    }

    public function testPanelHookExposesSendProbeControlsAsAdvancedMaintenanceChild(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml'
        );
        self::assertIsString($src);
        self::assertStringContainsString('weline-devrelay-send', $src);
        self::assertStringContainsString('/payment/dev-relay/panel-probe', $src);
        self::assertStringContainsString('发送探测', $src);
        self::assertStringContainsString('payment-dev-relay-panel__split', $src);
        self::assertStringContainsString('WelineAdvancedMaintenance', $src);
        self::assertStringContainsString('registerChild', $src);
        self::assertStringContainsString("CHILD_ID = 'payment-dev-relay'", $src);
        self::assertStringContainsString('weline-devrelay-webhook-search', $src);
        self::assertStringContainsString('weline-devrelay-webhook-select', $src);
        self::assertStringContainsString('provider_webhooks', file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/DevRelay.php'
        ));
        self::assertStringContainsString('defaultProviderWebhookEndpoints', file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/DevRelay.php'
        ));
        self::assertStringContainsString('PaymentMethodManager', file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/DevRelay.php'
        ));
        self::assertStringContainsString('Provider Webhook（复制到支付后台）', $src);
        self::assertStringNotContainsString('复制到 PayPal', $src);
        self::assertStringNotContainsString('<details>', $src);
        self::assertStringNotContainsString('WelinePanel.registerTab', $src);
        self::assertStringNotContainsString('weline-devrelay-token', $src);
    }

    public function testDemoTemplateExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/view/Frontend/dev-relay/demo.phtml');
        $src = file_get_contents(dirname(__DIR__, 3) . '/view/Frontend/dev-relay/demo.phtml');
        self::assertIsString($src);
        self::assertStringContainsString('DevRelay 探针演示', $src);
        self::assertStringContainsString('probePath', $src);
    }

    public function testIndexTemplateCopiesWebsiteBaseUrlAndToken(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/DevRelay/index.phtml');
        self::assertIsString($src);
        self::assertStringContainsString('dev-relay-website-base-url', $src);
        self::assertStringContainsString('dev-relay-user-api-token', $src);
        self::assertStringContainsString('dev-relay-worker-start', $src);
        self::assertStringContainsString('online_base_url', $src);
        self::assertStringContainsString('dev-relay-enabled', $src);
        self::assertStringContainsString('dev-relay-save-settings', $src);
        self::assertStringContainsString('postSaveSettings', $src);
    }

    public function testSettingsServiceExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/DevRelaySettingsService.php');
        $gate = file_get_contents(dirname(__DIR__, 3) . '/Service/DevRelayGateService.php');
        self::assertIsString($gate);
        self::assertStringContainsString('DevRelaySettingsService', $gate);
        self::assertStringContainsString('canOpenConsolePage', $gate);
    }

    public function testCliCommandsExist(): void
    {
        $base = dirname(__DIR__, 3) . '/Console/Payment/DevRelay';
        self::assertFileExists($base . '/Start.php');
        self::assertFileExists($base . '/Stop.php');
        self::assertFileExists($base . '/Status.php');
        self::assertFileExists($base . '/Run.php');
    }
}
