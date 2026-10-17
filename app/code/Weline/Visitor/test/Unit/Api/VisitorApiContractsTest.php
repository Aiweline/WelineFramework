<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Api;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorApiContractsPass().
 */
final class VisitorApiContractsTest extends TestCase
{
    public function testAllVisitorApiContractsPass(): void
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
            'VisitorPanelRestRoutes::testPanelJsUsesApiVisitorRestBase' => function () {
            $this->assert_VisitorPanelRestRoutes_testPanelJsUsesApiVisitorRestBase();
        },
            'VisitorPanelRestRoutes::testGeneratedFrontendRestContainsPanelAnalyticsRoutes' => function () {
            $this->assert_VisitorPanelRestRoutes_testGeneratedFrontendRestContainsPanelAnalyticsRoutes();
        },
            'VisitorPanelRestRoutes::testAnalyticsReportAcceptsPagePathScope' => function () {
            $this->assert_VisitorPanelRestRoutes_testAnalyticsReportAcceptsPagePathScope();
        },
        ];
    }

private function assert_VisitorPanelRestRoutes_testPanelJsUsesApiVisitorRestBase(): void
{
    $panel = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/weline-panel-visitor.js');
    self::assertStringContainsString("PANEL_REST_BASE = '/api/visitor/rest/v1'", $panel);
    self::assertStringContainsString("path: 'analytics/dashboard'", $panel);
    self::assertStringContainsString("path: 'analytics/report'", $panel);
    self::assertStringContainsString("path: 'analytics/buffer-stats'", $panel);
    self::assertStringContainsString("path: 'analytics/audit-report'", $panel);
    self::assertStringContainsString("path: 'analytics/channel-status'", $panel);
    // 概览/事件数据必须显式带当前站点 websiteId，禁止只传日期导致落到 website_id=0「像全站」。
    self::assertStringContainsString('websiteId: websiteId', $panel);
    self::assertStringContainsString('startDate: range.startDate', $panel);
    self::assertStringContainsString('endDate: range.endDate', $panel);
    self::assertStringContainsString('reportParams.pagePath = pagePath', $panel);
    self::assertStringContainsString('requestVisitor(\'analyticsReport\', reportParams)', $panel);
    self::assertStringContainsString('requestVisitor(\'analyticsDashboard\', dashboardParams)', $panel);
    self::assertStringContainsString('currentWebsiteScopeLabel', $panel);
    self::assertStringContainsString('data-wvp-action="scope-page"', $panel);
    self::assertStringContainsString('data-wvp-action="scope-website"', $panel);
}

private function assert_VisitorPanelRestRoutes_testAnalyticsReportAcceptsPagePathScope(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Api/Rest/V1/Analytics.php');
    self::assertStringContainsString("getParam('pagePath')", $src);
    self::assertStringContainsString("schema_fields_URL, '%' . \$pagePath . '%', 'like'", $src);
    self::assertStringContainsString("'scope' => \$pagePath !== '' ? 'page' : 'website'", $src);
}

private function assert_VisitorPanelRestRoutes_testGeneratedFrontendRestContainsPanelAnalyticsRoutes(): void
{
    self::assertTrue(\defined('BP'), 'BP must be defined by phpunit bootstrap');
    $routerFile = BP . 'generated/routers/frontend_rest_api.php';
    self::assertFileExists($routerFile, 'generated/routers/frontend_rest_api.php missing — run setup:upgrade --route for Weline_Visitor');
    /** @var array<string, mixed> $routes */
    $routes = include $routerFile;
    self::assertIsArray($routes);
    self::assertNotEmpty($routes, 'frontend_rest_api router table must not be empty');
    $required = ['visitor/rest/v1/analytics/dashboard::GET', 'visitor/rest/v1/analytics/report::GET', 'visitor/rest/v1/analytics/buffer-stats::GET', 'visitor/rest/v1/analytics/audit-report::GET', 'visitor/rest/v1/analytics/channel-status::GET', 'visitor/rest/v1/pixel::POST'];
    foreach ($required as $key) {
        self::assertArrayHasKey($key, $routes, "Missing {$key} in frontend_rest_api.php — regenerate Weline_Visitor routes " . 'then php bin/w setup:upgrade --hot');
        $rule = $routes[$key];
        self::assertIsArray($rule);
        self::assertSame('Weline_Visitor', $rule['module'] ?? null, $key);
    }
}
}
