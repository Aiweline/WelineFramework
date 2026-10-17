<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesBackendContractsPass().
 */
final class WebsitesBackendContractsTest extends TestCase
{
    public function testAllWebsitesBackendContractsPass(): void
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
            'R43MutableWorkbench::testEveryMutableWorkbenchHasAnExactBrowserMutationCase' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testEveryMutableWorkbenchHasAnExactBrowserMutationCase();
        },
            'R43MutableWorkbench::testDecisiveWritesUseBrowserControlsAndDurableAssertions' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testDecisiveWritesUseBrowserControlsAndDurableAssertions();
        },
            'R43MutableWorkbench::testEveryWriteCaseFinalizesBackendBrowserGuards' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testEveryWriteCaseFinalizesBackendBrowserGuards();
        },
            'R43MutableWorkbench::testFixtureFailsClosedAndCleansOnlyTaskOwnedState' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testFixtureFailsClosedAndCleansOnlyTaskOwnedState();
        },
            'R43MutableWorkbench::testBackupWorkbenchUsesTypedQueryOperationAndReportsTerminalState' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testBackupWorkbenchUsesTypedQueryOperationAndReportsTerminalState();
        },
            'R43MutableWorkbench::testBackupTableDiscoveryIsBoundedBeforeAutoloadingModels' => function () {
            $this->setUp_R43MutableWorkbench();
            $this->assert_R43MutableWorkbench_testBackupTableDiscoveryIsBoundedBeforeAutoloadingModels();
        },
            'SiteBuilderPlanCollect::testTypedSiteBuilderWorkbenchReplacesRawBrowserTransport' => function () {
            $this->assert_SiteBuilderPlanCollect_testTypedSiteBuilderWorkbenchReplacesRawBrowserTransport();
        },
            'StoreChannelManagement::testIndependentMenuLeavesUseExactRoutesAndAclSources' => function () {
            $this->assert_StoreChannelManagement_testIndependentMenuLeavesUseExactRoutesAndAclSources();
        },
            'StoreChannelManagement::testWorkbenchDelegatesReadsAndWritesToExistingDomainBoundaries' => function () {
            $this->assert_StoreChannelManagement_testWorkbenchDelegatesReadsAndWritesToExistingDomainBoundaries();
        },
            'StoreChannelManagement::testBothBrowserWritesAssertPostgresqlAndCleanup' => function () {
            $this->assert_StoreChannelManagement_testBothBrowserWritesAssertPostgresqlAndCleanup();
        },
        ];
    }

private string $spec_R43MutableWorkbench;

private string $fixture_R43MutableWorkbench;

private string $storeCopySpec_R43MutableWorkbench;

private string $backupTemplate_R43MutableWorkbench;

private string $backupService_R43MutableWorkbench;

private function setUp_R43MutableWorkbench(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Websites';
    $this->spec_R43MutableWorkbench = (string) file_get_contents($moduleRoot . '/Test/e2e/backend/Weline_Websites-r43-mutable-workbenches.spec.js');
    $this->fixture_R43MutableWorkbench = (string) file_get_contents($moduleRoot . '/Test/e2e/backend/Weline_Websites-r43-mutable-workbenches-fixture.php');
    $this->storeCopySpec_R43MutableWorkbench = (string) file_get_contents($moduleRoot . '/Test/e2e/backend/plan-p2c-copy.spec.js');
    $this->backupTemplate_R43MutableWorkbench = (string) file_get_contents($moduleRoot . '/view/templates/backend/Backup/index.phtml');
    $this->backupService_R43MutableWorkbench = (string) file_get_contents($moduleRoot . '/Service/WebsiteBackupService.php');
}

private function assert_R43MutableWorkbench_testEveryMutableWorkbenchHasAnExactBrowserMutationCase(): void
{
    foreach (['CK-R43-WEBSITES-WEBSITE-001', 'CK-R43-WEBSITES-DOMAIN-001', 'CK-R43-WEBSITES-SITE-BUILDER-001', 'CK-R43-WEBSITES-MAINTENANCE-001', 'CK-R43-WEBSITES-BACKUP-001'] as $caseId) {
        self::assertStringContainsString($caseId, $this->spec_R43MutableWorkbench);
    }
    foreach (['Weline_Websites::website', 'Weline_Websites::domain_service', 'Weline_Websites::site_builder_agent', 'Weline_Websites::website_maintenance', 'Weline_Websites::website_backup'] as $sourceId) {
        self::assertStringContainsString($sourceId, $this->spec_R43MutableWorkbench);
    }
    self::assertSame(5, substr_count($this->spec_R43MutableWorkbench, 'openCapability(page, CAPABILITIES.'));
    self::assertStringContainsString('openBackendMenuBySource(page, capability.sourceId', $this->spec_R43MutableWorkbench);
}

private function assert_R43MutableWorkbench_testDecisiveWritesUseBrowserControlsAndDurableAssertions(): void
{
    foreach (['#weline-manual-domain-submit', '#sbv1-create-session', '[data-maintenance-action="toggle"]', '[data-backup-submit]', 'button[type="submit"]'] as $selector) {
        self::assertStringContainsString($selector, $this->spec_R43MutableWorkbench);
    }
    self::assertStringContainsString("fixture('inspect'", $this->spec_R43MutableWorkbench);
    self::assertStringContainsString("fixture('cleanup'", $this->spec_R43MutableWorkbench);
    self::assertStringContainsString('PostgreSQL', $this->spec_R43MutableWorkbench);
    self::assertStringContainsString("selectOption('none')", $this->spec_R43MutableWorkbench);
    self::assertStringContainsString("searchParams.set('fake_mode', '1')", $this->spec_R43MutableWorkbench);
}

private function assert_R43MutableWorkbench_testEveryWriteCaseFinalizesBackendBrowserGuards(): void
{
    self::assertStringContainsString('installBackendBrowserGuards', $this->spec_R43MutableWorkbench);
    self::assertStringContainsString('installBackendBrowserGuards', $this->storeCopySpec_R43MutableWorkbench);
    self::assertSame(5, preg_match_all('/finally\s*\{\s*guards\.assertClean\(\);/', $this->spec_R43MutableWorkbench));
    self::assertSame(1, preg_match_all('/finally\s*\{\s*guards\.assertClean\(\);/', $this->storeCopySpec_R43MutableWorkbench));
}

private function assert_R43MutableWorkbench_testFixtureFailsClosedAndCleansOnlyTaskOwnedState(): void
{
    self::assertStringContainsString("getenv('WELINE_E2E_ISOLATED_DB') !== '1'", $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString("/^mig_clone_[a-z0-9_]+\$/D", $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString('requires_postgresql', $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString('refusing Websites cleanup outside r43 namespace', $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString("str_ends_with(\$domain, '.test')", $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString('deleteSessionByPublicId', $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString('deleteBackup', $this->fixture_R43MutableWorkbench);
    self::assertStringContainsString('JSON.stringify({ ...payload, action })', $this->spec_R43MutableWorkbench);
    self::assertStringNotContainsString('JSON.stringify({ action, ...payload })', $this->spec_R43MutableWorkbench);
}

private function assert_R43MutableWorkbench_testBackupWorkbenchUsesTypedQueryOperationAndReportsTerminalState(): void
{
    self::assertStringContainsString("resource('websites').manageWebsiteBackup", $this->backupTemplate_R43MutableWorkbench);
    self::assertStringNotContainsString("resource('websites').adminRequest", $this->backupTemplate_R43MutableWorkbench);
    self::assertStringContainsString("data-backup-state', 'created'", $this->backupTemplate_R43MutableWorkbench);
    self::assertStringContainsString("data-backup-state', 'failed'", $this->backupTemplate_R43MutableWorkbench);
    self::assertStringContainsString('data-backup-error', $this->backupTemplate_R43MutableWorkbench);
}

private function assert_R43MutableWorkbench_testBackupTableDiscoveryIsBoundedBeforeAutoloadingModels(): void
{
    $sourceFilter = strpos($this->backupService_R43MutableWorkbench, "file_get_contents(\$file->getPathname())");
    $autoload = strpos($this->backupService_R43MutableWorkbench, 'class_exists($class)');
    self::assertIsInt($sourceFilter);
    self::assertIsInt($autoload);
    self::assertLessThan($autoload, $sourceFilter);
    self::assertStringContainsString('websiteScopedTablesCache', $this->backupService_R43MutableWorkbench);
}

private function assert_SiteBuilderPlanCollect_testTypedSiteBuilderWorkbenchReplacesRawBrowserTransport(): void
{
    $provider = $this->source_SiteBuilderPlanCollect('/app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php');
    $handler = $this->source_SiteBuilderPlanCollect('/app/code/Weline/Websites/Service/AiWorkbench/SiteBuilderWorkbenchQueryHandler.php');
    $frontend = $this->source_SiteBuilderPlanCollect('/app/code/Weline/Websites/view/templates/backend/SiteBuilderAgent/index-v1.phtml');
    $workspace = $this->source_SiteBuilderPlanCollect('/app/code/Weline/Websites/view/templates/backend/SiteBuilderAgent/workspace.phtml');
    self::assertStringContainsString("'siteBuilderWorkbench'", $provider);
    self::assertStringContainsString('SiteBuilderWorkbenchQueryHandler::class', $provider);
    self::assertStringContainsString("'enum' => SiteBuilderWorkbenchQueryHandler::COMMANDS", $provider);
    self::assertStringContainsString('public const COMMANDS', $handler);
    self::assertStringContainsString('throw new \InvalidArgumentException', $handler);
    self::assertStringNotContainsString('AdminControllerBridge', $handler);
    foreach ([$frontend, $workspace] as $template) {
        self::assertStringContainsString("resource('websites').siteBuilderWorkbench", $template);
        self::assertStringContainsString('siteBuilderWorkbenchRequest', $template);
        self::assertStringNotContainsString('fetch(', $template);
        self::assertStringNotContainsString('XMLHttpRequest(', $template);
        self::assertStringNotContainsString('new EventSource(', $template);
        self::assertStringNotContainsString('bqAdmin', $template);
        self::assertStringNotContainsString("resource('websites').adminRequest", $template);
    }
    self::assertStringContainsString('websites.site_builder.v1.intake_draft', $frontend);
    self::assertStringContainsString("resource('websites').polishSiteBrief", $frontend);
    self::assertStringContainsString("'polishSiteBrief'", $provider);
}

private function source_SiteBuilderPlanCollect(string $path): string
{
    $source = \file_get_contents(BP . $path);
    self::assertIsString($source, 'Unable to read ' . $path);
    return $source;
}

private function assert_StoreChannelManagement_testIndependentMenuLeavesUseExactRoutesAndAclSources(): void
{
    $menu = (string) file_get_contents(BP . 'app/code/Weline/Websites/etc/backend/menu.xml');
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Websites/Controller/Backend/ScopeManagement.php');
    $expected = ['Weline_Websites::store_management' => ['stores', 'postCreateStore', 'websites/admin/website/index?focus=stores'], 'Weline_Websites::sales_channel_management' => ['channels', 'postCreateChannel', 'websites/admin/website/index?focus=channels']];
    foreach ($expected as $source => [$getMethod, $postMethod, $action]) {
        self::assertSame(1, substr_count($menu, 'source="' . $source . '"'));
        self::assertStringContainsString('action="' . $action . '"', $menu);
        self::assertMatchesRegularExpression('/#\[Acl\(\'' . preg_quote($source, '/') . '\',.*?\'Weline_Websites::website_service\'\)\]\s+public function ' . $getMethod . '\(\): string/s', $controller);
        self::assertMatchesRegularExpression('/#\[Acl\(\'' . preg_quote($source, '/') . '\',.*?\)\]\s+public function ' . $postMethod . '\(\): string/s', $controller);
    }
    self::assertStringContainsString("redirect('websites/admin/website'", $controller);
    self::assertStringContainsString("['focus' => 'stores']", $controller);
    self::assertStringContainsString("['focus' => 'channels']", $controller);
    self::assertStringContainsString('return_url', $controller);
    self::assertStringContainsString('isSafeWebsitesTreeReturnUrl', $controller);
    self::assertStringContainsString('function editStore(): string', $controller);
    self::assertStringContainsString('function editChannel(): string', $controller);
    self::assertStringContainsString('function postUpdateStore(): string', $controller);
    self::assertStringContainsString('function postUpdateChannel(): string', $controller);
    self::assertStringContainsString('Weline_Websites::store_save_after', $controller);
    self::assertStringContainsString('Weline_Websites::channel_save_after', $controller);
    self::assertStringContainsString('WebsiteScopeTreeService', $controller);
    self::assertStringContainsString("postNonNegativeInt('store_id', 0)", $controller);
    self::assertStringContainsString("postNonNegativeInt('channel_id', 0)", $controller);
    self::assertStringNotContainsString("postPositiveInt('store_id')", $controller);
    self::assertStringNotContainsString("postPositiveInt('channel_id')", $controller);
}

private function assert_StoreChannelManagement_testWorkbenchDelegatesReadsAndWritesToExistingDomainBoundaries(): void
{
    $service = (string) file_get_contents(BP . 'app/code/Weline/Websites/Service/StoreChannelAdminService.php');
    $template = (string) file_get_contents(BP . 'app/code/Weline/Websites/view/templates/backend/ScopeManagement/index.phtml');
    $editStore = (string) file_get_contents(BP . 'app/code/Weline/Websites/view/templates/backend/ScopeManagement/edit-store.phtml');
    $editChannel = (string) file_get_contents(BP . 'app/code/Weline/Websites/view/templates/backend/ScopeManagement/edit-channel.phtml');
    foreach (['StoreCatalogInterface', 'SalesChannelCatalogInterface', 'Store $storeModel', 'SalesChannel $channelModel'] as $dependency) {
        self::assertStringContainsString($dependency, $service);
    }
    self::assertStringContainsString('function updateStore(', $service);
    self::assertStringContainsString('function updateChannel(', $service);
    self::assertStringContainsString('->save()', $service);
    self::assertStringContainsString('data-testid="store-management-create-form"', $template);
    self::assertStringContainsString('data-testid="sales-channel-management-create-form"', $template);
    self::assertStringContainsString('store-management-edit-link', $template);
    self::assertStringContainsString("getHook('Weline_Websites::backend::store::form::sections-after')", $editStore);
    self::assertStringContainsString("getHook('Weline_Websites::backend::channel::form::sections-after')", $editChannel);
    self::assertStringContainsString('id="store-admin-edit-form"', $editStore);
    self::assertStringContainsString('id="channel-admin-edit-form"', $editChannel);
    self::assertStringContainsString('if (!$treeEmbed):', $editStore);
    self::assertStringContainsString('if (!$treeEmbed):', $editChannel);
    self::assertStringNotContainsString('dispatchHook', $editStore);
    self::assertStringNotContainsString('dispatchHook', $editChannel);
    self::assertStringNotContainsString('$storeId <= 0', $editStore);
    self::assertStringNotContainsString('$channelId <= 0', $editChannel);
    self::assertStringContainsString("array_key_exists('code', \$entity)", $editStore);
    self::assertStringContainsString("array_key_exists('code', \$entity)", $editChannel);
    self::assertGreaterThanOrEqual(2, substr_count($template, 'csrf="auto"'));
    self::assertStringContainsString('csrf="auto"', $editStore);
    self::assertStringContainsString('csrf="auto"', $editChannel);
    self::assertStringContainsString('id="scope-website-filter-form"', $template);
    self::assertStringContainsString('auto-submit="true"', $template);
    self::assertStringNotContainsString('切换网站', $template);
    $taglib = (string) file_get_contents(BP . 'app/code/Weline/Websites/Taglib/WebsiteSelect.php');
    self::assertStringContainsString("'auto-submit' => false", $taglib);
    self::assertStringContainsString('var autoSubmit =', $taglib);
    $hook = (string) file_get_contents(BP . 'app/code/Weline/Websites/hook.php');
    self::assertStringContainsString('Weline_Websites::backend::store::form::sections-after', $hook);
    self::assertStringContainsString('Weline_Websites::backend::channel::form::sections-after', $hook);
    $event = (string) file_get_contents(BP . 'app/code/Weline/Websites/event.php');
    self::assertStringContainsString('Weline_Websites::store_save_after', $event);
    self::assertStringContainsString('Weline_Websites::channel_save_after', $event);
}

private function assert_StoreChannelManagement_testBothBrowserWritesAssertPostgresqlAndCleanup(): void
{
    $spec = (string) file_get_contents(BP . 'app/code/Weline/Websites/Test/e2e/backend/Weline_Websites-menu-backend.spec.js');
    $fixture = (string) file_get_contents(BP . 'app/code/Weline/Websites/Test/e2e/backend/Weline_Websites-store-channel-fixture.php');
    self::assertStringContainsString('CK-R43-WEBSITES-STORE-001', $spec);
    self::assertStringContainsString('CK-R43-WEBSITES-CHANNEL-001', $spec);
    self::assertStringContainsString('Weline_Websites::store_management', $spec);
    self::assertStringContainsString('Weline_Websites::sales_channel_management', $spec);
    self::assertStringContainsString('r43_websites_requires_postgresql', $fixture);
    self::assertStringContainsString('r43_websites_cleanup', $fixture);
}
}
