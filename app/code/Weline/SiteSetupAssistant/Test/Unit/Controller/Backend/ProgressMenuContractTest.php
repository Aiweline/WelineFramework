<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 后台「建站助手」菜单 + 进度页双维度。
 */
final class ProgressMenuContractTest extends TestCase
{
    public function testMenuAndProgressPageExistForAssistantSearch(): void
    {
        $root = dirname(__DIR__, 4);
        $menu = (string)file_get_contents($root . '/etc/backend/menu.xml');
        $controller = (string)file_get_contents($root . '/Controller/Backend/Progress.php');
        $template = $root . '/view/templates/backend/Progress/index.phtml';

        self::assertFileExists($template);
        self::assertStringContainsString('title="建站助手"', $menu);
        self::assertStringContainsString('助手', $menu);
        self::assertStringContainsString('weline_sitesetupassistant/backend/progress/index', $menu);
        self::assertStringContainsString('Weline_Websites::website_service', $menu);
        self::assertStringContainsString('Weline_SiteSetupAssistant::site_setup_assistant', $menu);

        self::assertStringContainsString('class Progress extends BackendController', $controller);
        self::assertStringContainsString('SetupTaskCollector', $controller);
        self::assertStringContainsString('collectGlobalOverview', $controller);
        self::assertStringContainsString('isGlobal', $controller);
        self::assertStringContainsString("fetch('index')", $controller);

        $tpl = (string)file_get_contents($template);
        self::assertStringContainsString('ssa-progress-page', $tpl);
        self::assertStringContainsString('ssa-progress-list', $tpl);
        self::assertStringContainsString('ssa-progress-coverage', $tpl);
        self::assertStringContainsString('ssa-progress-search', $tpl);
        self::assertStringContainsString('ssa-progress-capsules', $tpl);
        self::assertStringContainsString('ssa-progress-status-tabs', $tpl);
        self::assertStringContainsString('data-status-tab', $tpl);
        self::assertStringContainsString('is-status-hidden', $tpl);
        self::assertStringContainsString('搜索任务', $tpl);
        self::assertStringContainsString('全站', $tpl);
        self::assertStringContainsString('allow-empty="true"', $tpl);
        self::assertStringContainsString('全局总览', $tpl);
        self::assertStringContainsString('w:websites:website:select', $tpl);
        // 后台 <base> 下禁止仅用相对 "?website_id="（会落到前缀根）
        self::assertStringContainsString('weline_sitesetupassistant/backend/progress/index', $tpl);
        self::assertStringContainsString('buildProgressUrl', $tpl);
        self::assertStringContainsString('action="<?= $h($progressPath) ?>"', $tpl);
        self::assertStringNotContainsString("href=\"?\"", $tpl);
        self::assertStringNotContainsString("http_build_query(['website_id' => \$capId])", $tpl);
    }
}
