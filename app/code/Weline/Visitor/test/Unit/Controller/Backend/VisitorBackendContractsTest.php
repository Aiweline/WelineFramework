<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Framework\Acl\Acl;
use Weline\Visitor\Controller\Backend\PixelDashboard;
use Weline\Visitor\Controller\Backend\TrafficChannel;
use Weline\Visitor\Service\PixelColdArchiveQueryService;
use Weline\Visitor\Service\Report\PixelDetailReportTabService;
use Weline\Visitor\Service\Report\PixelReportCatalog;

/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorBackendContractsPass().
 */
final class VisitorBackendContractsTest extends TestCase
{
    public function testAllVisitorBackendContractsPass(): void
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
            'PixelDashboardArchiveList::testArchiveListActionDeclaresAcl' => function () {
            $this->assert_PixelDashboardArchiveList_testArchiveListActionDeclaresAcl();
        },
            'PixelDashboardArchiveList::testControllerWiresColdQueryServiceAndTemplate' => function () {
            $this->assert_PixelDashboardArchiveList_testControllerWiresColdQueryServiceAndTemplate();
        },
            'PixelDashboardArchiveList::testArchiveListTemplateEnforcesWebsiteAndWindowHints' => function () {
            $this->assert_PixelDashboardArchiveList_testArchiveListTemplateEnforcesWebsiteAndWindowHints();
        },
            'PixelDashboardArchiveList::testHotListLinksToArchiveList' => function () {
            $this->assert_PixelDashboardArchiveList_testHotListLinksToArchiveList();
        },
            'PixelDashboardDetailReportTab::testDetailTemplateMountsOnlyEngineReportTabsShell' => function () {
            $this->assert_PixelDashboardDetailReportTab_testDetailTemplateMountsOnlyEngineReportTabsShell();
        },
            'PixelDashboardDetailReportTab::testControllerWiresDetailReportTabsViaService' => function () {
            $this->assert_PixelDashboardDetailReportTab_testControllerWiresDetailReportTabsViaService();
        },
            'PixelDashboardDetailReportTab::testMountedCodesCoverFullEnabledCatalog' => function () {
            $this->assert_PixelDashboardDetailReportTab_testMountedCodesCoverFullEnabledCatalog();
        },
            'PixelDashboardDetailReports::testDetailTemplateMountsAllReportCardsAndErrorBranches' => function () {
            $this->assert_PixelDashboardDetailReports_testDetailTemplateMountsAllReportCardsAndErrorBranches();
        },
            'PixelDashboardDetailReports::testControllerAssignsAllDetailReportPayloadsAndFlashWarnings' => function () {
            $this->assert_PixelDashboardDetailReports_testControllerAssignsAllDetailReportPayloadsAndFlashWarnings();
        },
            'PixelDashboardDetailReports::testI18nCoversDetailUnavailableCopy' => function () {
            $this->assert_PixelDashboardDetailReports_testI18nCoversDetailUnavailableCopy();
        },
            'PixelDashboardListShell::testListActionDeclaresPixelDashboardListAcl' => function () {
            $this->assert_PixelDashboardListShell_testListActionDeclaresPixelDashboardListAcl();
        },
            'PixelDashboardListShell::testListTemplateSupportsPaginationAndChannelFilters' => function () {
            $this->assert_PixelDashboardListShell_testListTemplateSupportsPaginationAndChannelFilters();
        },
            'PixelDashboardListShell::testControllerListWiresPaginationServiceAndFilterOptions' => function () {
            $this->assert_PixelDashboardListShell_testControllerListWiresPaginationServiceAndFilterOptions();
        },
            'PixelDashboardListShell::testShortHyphenRouteContractDocumentedInTemplates' => function () {
            $this->assert_PixelDashboardListShell_testShortHyphenRouteContractDocumentedInTemplates();
        },
            'TrackingVendorPrototype::testControllerExposesPrototypeRouteAndMockVendors' => function () {
            $this->assert_TrackingVendorPrototype_testControllerExposesPrototypeRouteAndMockVendors();
        },
            'TrackingVendorPrototype::testPrototypeTemplateHasThreeVariantsAndDevBanner' => function () {
            $this->assert_TrackingVendorPrototype_testPrototypeTemplateHasThreeVariantsAndDevBanner();
        },
            'TrackingVendorPrototype::testPrototypeCssUsesThemeTokensNotInventedPalette' => function () {
            $this->assert_TrackingVendorPrototype_testPrototypeCssUsesThemeTokensNotInventedPalette();
        },
            'TrafficChannelShell::testControllerDeclaresTrafficChannelAclTree' => function () {
            $this->assert_TrafficChannelShell_testControllerDeclaresTrafficChannelAclTree();
        },
            'TrafficChannelShell::testMenuXmlRegistersTrafficChannelUnderDataTools' => function () {
            $this->assert_TrafficChannelShell_testMenuXmlRegistersTrafficChannelUnderDataTools();
        },
            'TrafficChannelShell::testCreateEditAndToggleActionsExistWithReadonlyCodeInForm' => function () {
            $this->assert_TrafficChannelShell_testCreateEditAndToggleActionsExistWithReadonlyCodeInForm();
        },
            'VisitorPixelMenu::testMenuXmlRegistersPixelFeatureEntriesUnderDataTools' => function () {
            $this->assert_VisitorPixelMenu_testMenuXmlRegistersPixelFeatureEntriesUnderDataTools();
        },
        ];
    }

private function assert_PixelDashboardArchiveList_testArchiveListActionDeclaresAcl(): void
{
    $ref = new ReflectionClass(PixelDashboard::class);
    self::assertTrue($ref->hasMethod('archiveList'));
    $methodAcls = $ref->getMethod('archiveList')->getAttributes(Acl::class);
    self::assertCount(1, $methodAcls);
    $args = $methodAcls[0]->getArguments();
    self::assertSame('Weline_Visitor::pixel_dashboard_archive_list', $args[0] ?? null);
}

private function assert_PixelDashboardArchiveList_testControllerWiresColdQueryServiceAndTemplate(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $controller = (string) \file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
    self::assertStringContainsString('function archiveList', $controller);
    self::assertStringContainsString("fetch('archive_list')", $controller);
    self::assertStringContainsString('PixelColdArchiveQueryService', $controller);
    self::assertStringContainsString('queryPage', $controller);
    self::assertStringContainsString('translateColdArchiveError', $controller);
    self::assertStringContainsString('max_window_days', $controller);
    self::assertStringContainsString('PixelColdArchiveQueryService::MAX_WINDOW_DAYS', $controller);
    self::assertSame(31, PixelColdArchiveQueryService::MAX_WINDOW_DAYS);
}

private function assert_PixelDashboardArchiveList_testArchiveListTemplateEnforcesWebsiteAndWindowHints(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/backend/PixelDashboard/archive_list.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $src);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $src);
    self::assertStringNotContainsString('pixel_dashboard/', $src);
    self::assertStringContainsString('id="pixel-archive-list-filter-form"', $src);
    self::assertStringContainsString('name="websiteId"', $src);
    self::assertStringContainsString('w:websites:website:select', $src);
    self::assertStringContainsString('id="archive-filter-website"', $src);
    self::assertStringContainsString('allow-empty="false"', $src);
    self::assertStringContainsString('站点（必填）', $src);
    self::assertStringNotContainsString("'90d'", $src);
    self::assertStringContainsString('上一页', $src);
    self::assertStringContainsString('下一页', $src);
    self::assertStringContainsString('pageSize', $src);
    self::assertStringContainsString('不会静默扫描热表', $src);
}

private function assert_PixelDashboardArchiveList_testHotListLinksToArchiveList(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $listTpl = (string) \file_get_contents($root . '/view/templates/backend/PixelDashboard/list.phtml');
    self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $listTpl);
    self::assertStringContainsString('冷归档明细', $listTpl);
}

private function assert_PixelDashboardDetailReportTab_testDetailTemplateMountsOnlyEngineReportTabsShell(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/backend/PixelDashboard/detail.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('data-pixel-report-tabs', $src);
    self::assertStringContainsString('data-report-tab', $src);
    self::assertStringContainsString('report_tabs', $src);
    self::assertStringContainsString('active_report_tab', $src);
    self::assertStringContainsString('report_tab', $src);
    self::assertStringContainsString('引擎报表', $src);
    self::assertStringContainsString('drilldownExtras', $src);
    // 下钻须带 Tab 的 catalog filters（paid/social Tab 需 traffic_type）
    self::assertStringContainsString("\$tab['filters']", $src);
    // 事件价值 Tab 的派生均值列按行数据存在与否显示
    self::assertStringContainsString('avg_value', $src);
    // 不得一次硬编码挂满 catalog code（由 service 列表驱动）
    self::assertStringNotContainsString("'pixel_event_value'", $src);
    self::assertStringNotContainsString("'pixel_paid'", $src);
    self::assertStringNotContainsString("'pixel_social'", $src);
    self::assertStringNotContainsString("'pixel_value_by_channel'", $src);
}

private function assert_PixelDashboardDetailReportTab_testControllerWiresDetailReportTabsViaService(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $controller = (string) \file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
    self::assertStringContainsString('PixelDetailReportTabService', $controller);
    self::assertStringContainsString('buildDetailReportTabs', $controller);
    self::assertStringContainsString("'report_tabs'", $controller);
    self::assertStringContainsString("'active_report_tab'", $controller);
    self::assertStringContainsString('fetchHotReportEventRows', $controller);
    self::assertStringContainsString('FIRST_TAB_CODE', $controller);
}

private function assert_PixelDashboardDetailReportTab_testMountedCodesCoverFullEnabledCatalog(): void
{
    self::assertSame(['pixel_channels', 'pixel_traffic_type', 'pixel_paid', 'pixel_social', 'pixel_event_value', 'pixel_value_by_channel'], PixelDetailReportTabService::MOUNTED_REPORT_CODES);
    self::assertSame('pixel_channels', PixelDetailReportTabService::FIRST_TAB_CODE);
    $catalogCodes = (new PixelReportCatalog())->codes();
    self::assertSame([], array_diff($catalogCodes, PixelDetailReportTabService::MOUNTED_REPORT_CODES));
    self::assertSame([], array_diff(PixelDetailReportTabService::MOUNTED_REPORT_CODES, $catalogCodes));
}

private function assert_PixelDashboardDetailReports_testDetailTemplateMountsAllReportCardsAndErrorBranches(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $tpl = $root . '/view/templates/backend/PixelDashboard/detail.phtml';
    self::assertFileExists($tpl);
    $src = (string) \file_get_contents($tpl);
    self::assertStringContainsString('id="ecommerce-funnel"', $src);
    self::assertStringContainsString('id="ecommerce-revenue"', $src);
    self::assertStringContainsString('id="ecommerce-items"', $src);
    self::assertStringContainsString('id="path-exploration"', $src);
    self::assertStringContainsString('id="retention"', $src);
    self::assertStringContainsString('data-pixel-report-tabs', $src);
    self::assertStringContainsString('w:websites:website:select', $src);
    self::assertStringContainsString('detail-filter-website', $src);
    self::assertStringNotContainsString('<select name="websiteId"', $src);
    self::assertStringContainsString('电商漏斗暂不可用（扁平列未就绪或查询失败）。', $src);
    self::assertStringContainsString('购成收入暂不可用（扁平列未就绪或查询失败）。', $src);
    self::assertStringContainsString('商品表现暂不可用（扁平列或 items 未就绪）。', $src);
    self::assertStringContainsString('路径探索暂不可用（扁平列未就绪或查询失败）。', $src);
    self::assertStringContainsString('留存分析暂不可用（扁平列未就绪或查询失败）。', $src);
    self::assertStringContainsString('该 Tab 暂无聚合数据（需扁平列就绪且时间窗内有事件）。', $src);
    // 错误分支必须走 alert-warning，不得静默吞掉
    self::assertGreaterThanOrEqual(5, \substr_count($src, 'alert alert-warning'));
}

private function assert_PixelDashboardDetailReports_testControllerAssignsAllDetailReportPayloadsAndFlashWarnings(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $controller = (string) \file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
    foreach (['ecommerce_funnel', 'ecommerce_revenue', 'ecommerce_items', 'path_exploration', 'retention', 'report_tabs'] as $assignKey) {
        self::assertStringContainsString("'" . $assignKey . "'", $controller, $assignKey);
    }
    self::assertStringContainsString('buildEcommerceFunnel', $controller);
    self::assertStringContainsString('buildEcommerceRevenue', $controller);
    self::assertStringContainsString('buildEcommerceItems', $controller);
    self::assertStringContainsString('buildPathExploration', $controller);
    self::assertStringContainsString('buildRetention', $controller);
    self::assertStringContainsString('buildDetailReportTabs', $controller);
    self::assertStringContainsString('assignWebsiteSelectOptions', $controller);
    self::assertStringContainsString('电商漏斗暂不可用：%{1}', $controller);
    self::assertStringContainsString('购成收入暂不可用：%{1}', $controller);
    self::assertStringContainsString('商品表现暂不可用：%{1}', $controller);
    self::assertStringContainsString('路径探索暂不可用：%{1}', $controller);
    self::assertStringContainsString('留存分析暂不可用：%{1}', $controller);
}

private function assert_PixelDashboardDetailReports_testI18nCoversDetailUnavailableCopy(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $zh = (string) \file_get_contents($root . '/i18n/zh_Hans_CN.csv');
    $en = (string) \file_get_contents($root . '/i18n/en_US.csv');
    foreach (['电商漏斗暂不可用（扁平列未就绪或查询失败）。', '购成收入暂不可用（扁平列未就绪或查询失败）。', '商品表现暂不可用（扁平列或 items 未就绪）。', '路径探索暂不可用（扁平列未就绪或查询失败）。', '留存分析暂不可用（扁平列未就绪或查询失败）。', '该 Tab 暂无聚合数据（需扁平列就绪且时间窗内有事件）。'] as $key) {
        self::assertStringContainsString($key, $zh, $key);
        self::assertStringContainsString($key, $en, $key);
    }
}

private function assert_PixelDashboardListShell_testListActionDeclaresPixelDashboardListAcl(): void
{
    $ref = new ReflectionClass(PixelDashboard::class);
    self::assertTrue($ref->hasMethod('list'));
    $methodAcls = $ref->getMethod('list')->getAttributes(Acl::class);
    self::assertCount(1, $methodAcls);
    /** @var Acl $acl */
    $acl = $methodAcls[0]->newInstance();
    self::assertSame('Weline_Visitor::pixel_dashboard_list', $acl->getData('source_id'));
}

private function assert_PixelDashboardListShell_testListTemplateSupportsPaginationAndChannelFilters(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $listTpl = $root . '/view/templates/backend/PixelDashboard/list.phtml';
    self::assertFileExists($listTpl);
    $src = (string) \file_get_contents($listTpl);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $src);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/index', $src);
    self::assertStringContainsString('上一页', $src);
    self::assertStringContainsString('下一页', $src);
    self::assertStringContainsString("\$pagination", $src);
    self::assertStringContainsString("'page'", $src);
    // C03：筛选表单
    self::assertStringContainsString('id="pixel-list-filter-form"', $src);
    self::assertStringContainsString('method="get"', $src);
    self::assertStringContainsString('name="channel_code"', $src);
    self::assertStringContainsString('name="traffic_type"', $src);
    self::assertStringContainsString('name="utm_source"', $src);
    self::assertStringContainsString('name="utm_medium"', $src);
    self::assertStringContainsString('name="utm_campaign"', $src);
    self::assertStringContainsString('name="range"', $src);
    self::assertStringContainsString('<lang>应用筛选</lang>', $src);
    self::assertStringContainsString('weline-pixel-list-toolbar__primary', $src);
    self::assertStringContainsString('weline-pixel-list-toolbar__primary-meta', $src);
    self::assertStringContainsString('weline-pixel-list-toolbar__advanced', $src);
    self::assertStringContainsString('overflow: hidden', $src);
    self::assertStringContainsString('overflow-x: auto', $src);
    self::assertStringContainsString('min-width: 52rem', $src);
    self::assertStringContainsString('weline-pixel-list-url', $src);
    self::assertStringContainsString('max-width: 18rem', $src);
    self::assertStringContainsString('data-truncate="true"', $src);
    self::assertStringNotContainsString('width: max-content', $src);
    self::assertStringContainsString('weline-pixel-list-field-hint', $src);
    // 翻页须保留归因 query
    self::assertStringContainsString("'channel_code' => \$selectedChannel", $src);
    self::assertStringContainsString("'utm_source' => \$selectedUtmSource", $src);
}

private function assert_PixelDashboardListShell_testControllerListWiresPaginationServiceAndFilterOptions(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $controller = (string) \file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
    self::assertStringContainsString('function list', $controller);
    self::assertStringContainsString("fetch('list')", $controller);
    self::assertStringContainsString('getDashboardEventListPage', $controller);
    self::assertStringContainsString("'list_ready', true", $controller);
    self::assertStringContainsString('pixel_dashboard_list', $controller);
    self::assertStringContainsString('traffic_type_options', $controller);
    self::assertStringContainsString('buildListDisplayFilters', $controller);
    $index = (string) \file_get_contents($root . '/view/templates/backend/PixelDashboard/index.phtml');
    self::assertStringContainsString('pixel-dashboard/list', $index);
    self::assertStringContainsString('<lang>事件列表</lang>', $index);
    self::assertStringContainsString('w:websites:website:select', $index);
    self::assertStringContainsString('index-filter-website', $index);
    self::assertStringNotContainsString('<select name="websiteId"', $index);
    self::assertStringContainsString('assignWebsiteSelectOptions', $controller);
}

private function assert_PixelDashboardListShell_testShortHyphenRouteContractDocumentedInTemplates(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    foreach (['index.phtml', 'list.phtml', 'detail.phtml'] as $file) {
        $src = (string) \file_get_contents($root . '/view/templates/backend/PixelDashboard/' . $file);
        self::assertStringContainsString('pixel-dashboard/', $src, $file);
        self::assertStringNotContainsString('pixel_dashboard/', $src, $file . ' 不得使用下划线路由');
    }
}

private function assert_TrackingVendorPrototype_testControllerExposesPrototypeRouteAndMockVendors(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendorPrototype.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('TrackingVendorPrototype', $src);
    self::assertStringContainsString('PROTOTYPE ONLY', $src);
    self::assertStringContainsString("'source' => 'module'", $src);
    self::assertStringContainsString("'source' => 'custom'", $src);
    self::assertStringContainsString("'mode' => 'sandbox'", $src);
    self::assertStringContainsString('dev_no_report', $src);
    self::assertStringContainsString('ga4', $src);
    self::assertStringContainsString('gtm', $src);
    self::assertStringContainsString('custom_demo', $src);
    self::assertStringContainsString('event_map', $src);
    self::assertStringContainsString('sandbox_js', $src);
}

private function assert_TrackingVendorPrototype_testPrototypeTemplateHasThreeVariantsAndDevBanner(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendorPrototype/index.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('data-testid="tracking-vendor-prototype"', $src);
    self::assertStringContainsString('data-variant-panel="1"', $src);
    self::assertStringContainsString('data-variant-panel="2"', $src);
    self::assertStringContainsString('data-variant-panel="3"', $src);
    self::assertStringContainsString('tvp-dev-banner', $src);
    self::assertStringContainsString('DEV：不上报', $src);
    self::assertStringContainsString('tvp-fold', $src);
    self::assertStringContainsString('tvp-v2-item', $src);
    self::assertStringContainsString('tvp-state-fold', $src);
    self::assertStringContainsString('tvp-variant-switcher', $src);
    self::assertStringContainsString('事件搭接', $src);
    self::assertStringContainsString('双 inject', $src);
    self::assertStringContainsString('source-module', $src);
    self::assertStringContainsString('source-custom', $src);
}

private function assert_TrackingVendorPrototype_testPrototypeCssUsesThemeTokensNotInventedPalette(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/css/tracking-vendor-prototype.css';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('--weline-', $src);
    self::assertStringNotContainsString('#F4F1EA', $src);
    self::assertStringNotContainsString('#D97757', $src);
}

private function assert_TrafficChannelShell_testControllerDeclaresTrafficChannelAclTree(): void
{
    $class = new ReflectionClass(TrafficChannel::class);
    $classAcls = $class->getAttributes(Acl::class);
    self::assertCount(1, $classAcls);
    /** @var Acl $classAcl */
    $classAcl = $classAcls[0]->newInstance();
    self::assertSame('Weline_Visitor::traffic_channel', $classAcl->getData('source_id'));
    self::assertSame('Weline_Backend::data_tools_group', $classAcl->getData('parent_source'));
    $index = $class->getMethod('index');
    $methodAcls = $index->getAttributes(Acl::class);
    self::assertCount(1, $methodAcls);
    /** @var Acl $methodAcl */
    $methodAcl = $methodAcls[0]->newInstance();
    self::assertSame('Weline_Visitor::traffic_channel_index', $methodAcl->getData('source_id'));
}

private function assert_TrafficChannelShell_testMenuXmlRegistersTrafficChannelUnderDataTools(): void
{
    $menu = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/etc/backend/menu.xml');
    self::assertStringContainsString('Weline_Visitor::traffic_channel', $menu);
    self::assertStringContainsString('visitor/backend/traffic-channel/index', $menu);
    self::assertStringContainsString('Weline_Backend::data_tools_group', $menu);
}

private function assert_TrafficChannelShell_testCreateEditAndToggleActionsExistWithReadonlyCodeInForm(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $ref = new ReflectionClass(TrafficChannel::class);
    foreach (['getAdd', 'postAdd', 'getEdit', 'postEdit', 'postToggleEnabled', 'getDetail'] as $method) {
        self::assertTrue($ref->hasMethod($method), $method);
    }
    self::assertFalse($ref->hasMethod('postDelete'));
    self::assertFalse($ref->hasMethod('delete'));
    self::assertSame('Weline_Visitor::traffic_channel_edit', $ref->getMethod('getEdit')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id'));
    self::assertSame('Weline_Visitor::traffic_channel_detail', $ref->getMethod('getDetail')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id'));
    self::assertSame('Weline_Visitor::traffic_channel_toggle', $ref->getMethod('postToggleEnabled')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id'));
    $index = (string) \file_get_contents($root . '/view/templates/backend/TrafficChannel/index.phtml');
    self::assertStringContainsString('traffic-channel/getEdit', $index);
    self::assertStringContainsString('traffic-channel/getDetail', $index);
    self::assertStringContainsString('postToggleEnabled', $index);
    self::assertStringContainsString('停用', $index);
    self::assertStringContainsString('详情', $index);
    $form = (string) \file_get_contents($root . '/view/templates/backend/TrafficChannel/form.phtml');
    self::assertStringContainsString('code_readonly', $form);
    self::assertStringContainsString('渠道码创建后不可修改', $form);
    self::assertStringContainsString('readonly', $form);
    // B06：投放链接预览/复制
    self::assertStringContainsString('channel-landing-url', $form);
    self::assertStringContainsString('复制链接', $form);
    self::assertStringContainsString('landing_path', $form);
    self::assertFileExists($root . '/Service/PixelChannelLandingUrlService.php');
    self::assertStringContainsString('js-copy-landing', $index);
}

private function assert_VisitorPixelMenu_testMenuXmlRegistersPixelFeatureEntriesUnderDataTools(): void
{
    $menu = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/etc/backend/menu.xml');
    self::assertStringContainsString('Weline_Backend::data_tools_group', $menu);
    self::assertStringContainsString('Weline_Visitor::pixel_dashboard', $menu);
    self::assertStringContainsString('title="像素分析"', $menu);
    self::assertStringContainsString('Weline_Visitor::pixel_dashboard_index', $menu);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/index', $menu);
    self::assertStringContainsString('title="事件看板"', $menu);
    self::assertStringContainsString('Weline_Visitor::pixel_dashboard_list', $menu);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/list', $menu);
    self::assertStringContainsString('title="热表明细"', $menu);
    self::assertStringContainsString('Weline_Visitor::pixel_dashboard_archive_list', $menu);
    self::assertStringContainsString('visitor/backend/pixel-dashboard/archive-list', $menu);
    self::assertStringContainsString('title="冷归档明细"', $menu);
    self::assertStringContainsString('Weline_Visitor::traffic_channel', $menu);
    self::assertStringContainsString('visitor/backend/traffic-channel/index', $menu);
    self::assertStringContainsString('title="流量渠道"', $menu);
    // 禁止漏掉 backend area 段
    self::assertStringNotContainsString('action="visitor/pixel-dashboard/', $menu);
    self::assertStringNotContainsString('action="visitor/traffic-channel/', $menu);
}
}
