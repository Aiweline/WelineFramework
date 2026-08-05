<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * F01–F04b + D07：detail 详情报表卡片/错误分支/assign 契约（防截图「暂不可用」无测）。
 */
final class PixelDashboardDetailReportsContractTest extends TestCase
{
    public function testDetailTemplateMountsAllReportCardsAndErrorBranches(): void
    {
        $root = dirname(__DIR__, 4);
        $tpl = $root . '/view/templates/Backend/PixelDashboard/detail.phtml';
        self::assertFileExists($tpl);
        $src = (string)\file_get_contents($tpl);

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

    public function testControllerAssignsAllDetailReportPayloadsAndFlashWarnings(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');

        foreach ([
            'ecommerce_funnel',
            'ecommerce_revenue',
            'ecommerce_items',
            'path_exploration',
            'retention',
            'report_tabs',
        ] as $assignKey) {
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

    public function testI18nCoversDetailUnavailableCopy(): void
    {
        $root = dirname(__DIR__, 4);
        $zh = (string)\file_get_contents($root . '/i18n/zh_Hans_CN.csv');
        $en = (string)\file_get_contents($root . '/i18n/en_US.csv');

        foreach ([
            '电商漏斗暂不可用（扁平列未就绪或查询失败）。',
            '购成收入暂不可用（扁平列未就绪或查询失败）。',
            '商品表现暂不可用（扁平列或 items 未就绪）。',
            '路径探索暂不可用（扁平列未就绪或查询失败）。',
            '留存分析暂不可用（扁平列未就绪或查询失败）。',
            '该 Tab 暂无聚合数据（需扁平列就绪且时间窗内有事件）。',
        ] as $key) {
            self::assertStringContainsString($key, $zh, $key);
            self::assertStringContainsString($key, $en, $key);
        }
    }
}
