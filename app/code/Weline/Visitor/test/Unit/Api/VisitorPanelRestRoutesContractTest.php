<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * 开发面板「访问事件」依赖 generated frontend_rest_api 中的 Visitor REST 路由。
 * 路由被增量/指纹跳扫误清后，面板会刷屏 GET /api/visitor/... 404 Not Found。
 */
final class VisitorPanelRestRoutesContractTest extends TestCase
{
    public function testPanelJsUsesApiVisitorRestBase(): void
    {
        $panel = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-panel-visitor.js'
        );
        self::assertStringContainsString("PANEL_REST_BASE = '/api/visitor/rest/v1'", $panel);
        self::assertStringContainsString("path: 'analytics/dashboard'", $panel);
        self::assertStringContainsString("path: 'analytics/report'", $panel);
        self::assertStringContainsString("path: 'analytics/buffer-stats'", $panel);
        self::assertStringContainsString("path: 'analytics/audit-report'", $panel);
        self::assertStringContainsString("path: 'analytics/channel-status'", $panel);
    }

    public function testGeneratedFrontendRestContainsPanelAnalyticsRoutes(): void
    {
        self::assertTrue(\defined('BP'), 'BP must be defined by phpunit bootstrap');
        $routerFile = BP . 'generated/routers/frontend_rest_api.php';

        self::assertFileExists(
            $routerFile,
            'generated/routers/frontend_rest_api.php missing — run setup:upgrade --route for Weline_Visitor'
        );

        /** @var array<string, mixed> $routes */
        $routes = include $routerFile;
        self::assertIsArray($routes);
        self::assertNotEmpty($routes, 'frontend_rest_api router table must not be empty');

        $required = [
            'visitor/rest/v1/analytics/dashboard::GET',
            'visitor/rest/v1/analytics/report::GET',
            'visitor/rest/v1/analytics/buffer-stats::GET',
            'visitor/rest/v1/analytics/audit-report::GET',
            'visitor/rest/v1/analytics/channel-status::GET',
            'visitor/rest/v1/pixel::POST',
        ];

        foreach ($required as $key) {
            self::assertArrayHasKey(
                $key,
                $routes,
                "Missing {$key} in frontend_rest_api.php — regenerate Weline_Visitor routes "
                . 'then php bin/w setup:upgrade --hot'
            );
            $rule = $routes[$key];
            self::assertIsArray($rule);
            self::assertSame('Weline_Visitor', $rule['module'] ?? null, $key);
        }
    }
}
