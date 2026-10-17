<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Observer\MaintenanceInterceptor;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllMaintenanceObserverContractsPass().
 */
final class MaintenanceObserverContractsTest extends TestCase
{
    public function testAllMaintenanceObserverContractsPass(): void
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
            'LocaleCatalogChangedObserver::testLocaleCatalogChangedEventRepublishesMaintenanceStaticPages' => function () {
            $this->assert_LocaleCatalogChangedObserver_testLocaleCatalogChangedEventRepublishesMaintenanceStaticPages();
        },
            'MaintenanceInterceptor::testMaintenanceWorkerAllowsStaticAssetsBeforeMaintenanceResponse' => function () {
            $this->assert_MaintenanceInterceptor_testMaintenanceWorkerAllowsStaticAssetsBeforeMaintenanceResponse();
        },
            'MaintenanceInterceptor::testWhitelistedMaintenanceAssetPaths' => function () {
            $this->assert_MaintenanceInterceptor_testWhitelistedMaintenanceAssetPaths();
        },
            'MaintenanceInterceptor::testWaitGiftApiPathIsWhitelistedInSource' => function () {
            $this->assert_MaintenanceInterceptor_testWaitGiftApiPathIsWhitelistedInSource();
        },
            'MaintenanceInterceptor::testBackendAreaAutoBypassesMaintenance' => function () {
            $this->assert_MaintenanceInterceptor_testBackendAreaAutoBypassesMaintenance();
        },
            'MaintenanceInterceptorEarlyReturn::testMaintenanceOffReturnsBeforeFullUriParse' => function () {
            $this->assert_MaintenanceInterceptorEarlyReturn_testMaintenanceOffReturnsBeforeFullUriParse();
        },
            'MaintenanceRecoveryCheck::testInterceptorShortCircuitsRecoveryProbesBeforeBusinessRouting' => function () {
            $this->assert_MaintenanceRecoveryCheck_testInterceptorShortCircuitsRecoveryProbesBeforeBusinessRouting();
        },
            'MaintenanceRecoveryCheck::testFrontendWaitModalUsesLightweightRecoveryCheck' => function () {
            $this->assert_MaintenanceRecoveryCheck_testFrontendWaitModalUsesLightweightRecoveryCheck();
        },
        ];
    }

private function assert_LocaleCatalogChangedObserver_testLocaleCatalogChangedEventRepublishesMaintenanceStaticPages(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $lifecycleSource = (string) \file_get_contents($root . '/app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php');
    $eventXml = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/etc/event.xml');
    $observerSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/LocaleCatalogChangedObserver.php');
    $templateSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/view/templates/maintenance.phtml');
    $generatorSource = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Service/MaintenanceStaticGenerator.php');
    self::assertStringContainsString("dispatch('Weline_I18n::locale_catalog_changed')", $lifecycleSource);
    self::assertStringContainsString('Weline_I18n::locale_catalog_changed', $eventXml);
    self::assertStringContainsString('Weline_Framework_Setup::upgrade_after', $eventXml);
    self::assertStringContainsString('LocaleCatalogChangedObserver', $observerSource);
    self::assertStringContainsString('publishAll', $observerSource);
    self::assertStringContainsString('data-lang=', $templateSource);
    self::assertStringContainsString('buildLocalePath', $templateSource);
    self::assertStringNotContainsString('MaintenanceStaticPage::publicHtmlUrl', $templateSource);
    self::assertStringContainsString('ActiveLocaleCodeProvider', $generatorSource);
    self::assertStringContainsString('pruneObsoleteStaticFiles', $generatorSource);
}

private function assert_MaintenanceInterceptor_testMaintenanceWorkerAllowsStaticAssetsBeforeMaintenanceResponse(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString("defined('WLS_MAINTENANCE_WORKER')", $source);
    self::assertStringContainsString('shouldServeMaintenanceResponse', $source);
    self::assertStringContainsString("if (!\$this->shouldServeMaintenanceResponse(\$pure_uri))", $source);
    self::assertStringContainsString("'/view/statics/'", $source);
    self::assertStringContainsString("'/static/'", $source);
}

private function assert_MaintenanceInterceptor_testWhitelistedMaintenanceAssetPaths(): void
{
    $interceptor = new MaintenanceInterceptor();
    $method = new \ReflectionMethod(MaintenanceInterceptor::class, 'isWhitelisted');
    $method->setAccessible(true);
    self::assertFalse($method->invoke($interceptor, '/'));
    self::assertTrue($method->invoke($interceptor, '/Weline/Theme/view/statics/ui/pages/weline-maintenance.css'));
    self::assertTrue($method->invoke($interceptor, '/Weline/Theme/view/statics/ui/weline-ui.js'));
    self::assertTrue($method->invoke($interceptor, '/static/default/Weline/Theme/view/statics/ui/weline-foundation.css'));
    self::assertTrue($method->invoke($interceptor, '/pub/errors/maintenance/zh_Hans_CN.html'));
}

private function assert_MaintenanceInterceptor_testWaitGiftApiPathIsWhitelistedInSource(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString("'/maintenance/frontend/wait-gift'", $source);
    self::assertStringContainsString('WaitGiftService::COOKIE_GATE', $source);
}

private function assert_MaintenanceInterceptor_testBackendAreaAutoBypassesMaintenance(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString("\$area === 'backend' || \$area === 'rest_backend'", $source);
    self::assertStringContainsString('X-Weline-Maintenance', $source);
    self::assertStringContainsString('checkBackendPath($original_uri, $parse)', $source);
    self::assertStringContainsString('UrlParser::parse($uri)', $source);
}

private function assert_MaintenanceInterceptorEarlyReturn_testMaintenanceOffReturnsBeforeFullUriParse(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString('关闭时尽早返回，不做完整 URI 解析', $source);
    self::assertMatchesRegularExpression("/if\\s*\\(\\s*!Env::system\\('maintenance'\\)\\s*\\)\\s*\\{\\s*return;/s", $source);
    self::assertTrue(class_exists(MaintenanceInterceptor::class));
}

private function assert_MaintenanceRecoveryCheck_testInterceptorShortCircuitsRecoveryProbesBeforeBusinessRouting(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Observer/MaintenanceInterceptor.php');
    self::assertStringContainsString('tryHandleRecoveryCheck', $source);
    self::assertStringContainsString('isRecoveryCheckRequest', $source);
    self::assertStringContainsString('/maintenance/frontend/recovery-check', $source);
    self::assertStringContainsString('HTTP_X_MAINTENANCE_RECOVERY_CHECK', $source);
    self::assertStringContainsString('_maintenance_recovery_probe', $source);
    self::assertStringContainsString('X-Weline-Maintenance-Recovery', $source);
    self::assertMatchesRegularExpression('/tryHandleRecoveryCheck\(\$pureEarly\).*tryHandleWaitGiftApi\(\$pureEarly\)/s', $source);
}

private function assert_MaintenanceRecoveryCheck_testFrontendWaitModalUsesLightweightRecoveryCheck(): void
{
    $asyncJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/view/statics/js/maintenance-async-wait.js');
    $welineJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline.js');
    self::assertStringContainsString('/maintenance/frontend/recovery-check', $asyncJs);
    self::assertStringContainsString('X-Maintenance-Recovery-Check', $asyncJs);
    self::assertStringNotContainsString("fetch(window.location.pathname + window.location.search", $asyncJs);
    // Framework only lazy-loads the Maintenance module on 503.
    self::assertStringContainsString('maintenanceAsyncWait', $welineJs);
    self::assertStringContainsString('installMaintenanceHandlerLazyBridge', $welineJs);
    self::assertStringNotContainsString('weline-maintenance-wait-modal', $welineJs);
}
}
