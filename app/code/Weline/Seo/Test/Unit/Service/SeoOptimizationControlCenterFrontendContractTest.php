<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SeoOptimizationControlCenterFrontendContractTest extends TestCase
{
    public function testOptimizationConsoleUsesItsAclScopedDirectoryProjection(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/Backend/Optimization/index.phtml'
        );

        self::assertStringContainsString(
            "resource('seo_optimization_control')",
            $template
        );
        self::assertStringContainsString(
            'state.directoryApi.optimizationControlCenterSnapshot({sites_only:true})',
            $template
        );
        self::assertStringContainsString('function retry(', $template);
        self::assertStringContainsString(
            'Array.isArray(response.sites)?response.sites:items(response)',
            $template
        );
        self::assertStringNotContainsString("resource('websites')", $template);
        self::assertStringNotContainsString(
            'state.websitesApi.getWebsiteList({})',
            $template
        );
        self::assertStringContainsString('searchQueryCloud', $template);
        self::assertStringContainsString('siteEventHeat', $template);
        self::assertStringContainsString('syncSearchQueryCloud', $template);
        self::assertStringContainsString('evolveFromQueryHeat', $template);
        self::assertStringContainsString('seo-opt__cloud', $template);
        self::assertStringContainsString('GSC 词云与访客事件', $template);
        self::assertStringContainsString('syncCloudButton', $template);
        self::assertStringContainsString('evolveHeatButton', $template);
        self::assertStringContainsString("item.source==='search_query'", $template);
        self::assertStringContainsString('eventHeatGscFallback', $template);
        self::assertStringContainsString('当前无访客事件，以下为 GSC 搜索词热度', $template);
        self::assertStringContainsString('eventHeatVisitorHint', $template);
        self::assertStringContainsString('真实访客 PV 需站点上线', $template);
        self::assertStringContainsString('evolveScheduleHint', $template);
        self::assertStringContainsString('evolveScheduleHintByMode', $template);
        self::assertStringContainsString('renderScheduleHint', $template);
        self::assertStringContainsString('function withTimeout(', $template);
        self::assertStringContainsString('withTimeout(state.directoryApi.optimizationControlCenterSnapshot({sites_only:true}),8000)', $template);
        self::assertStringContainsString('小时调度默认跟随上方自动化模式', $template);
        self::assertStringContainsString('当前为影子模式：小时调度只分析不改稿', $template);
        self::assertStringContainsString('.seo-opt__panel-head h2{margin:4px 0 0;font-size:19px;color:var(--ink);font-weight:800', $template);
        self::assertStringContainsString('暂无访客事件或搜索词热度', $template);
    }
}
