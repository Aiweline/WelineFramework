<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllOpsViewContractsPass().
 */
final class OpsViewContractsTest extends TestCase
{
    public function testAllOpsViewContractsPass(): void
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
            'OpsDeskTemplate::test_desk_template_has_unified_entry_hooks' => function () {
            $this->assert_OpsDeskTemplate_test_desk_template_has_unified_entry_hooks();
        },
            'OpsDeskTemplate::test_backend_menu_registers_ai_ops_group' => function () {
            $this->assert_OpsDeskTemplate_test_backend_menu_registers_ai_ops_group();
        },
            'OpsDeskTemplate::test_lane_contributions_exist_for_engines' => function () {
            $this->assert_OpsDeskTemplate_test_lane_contributions_exist_for_engines();
        },
            'OpsDeskTemplate::test_ops_task_status_machine_includes_async_states' => function () {
            $this->assert_OpsDeskTemplate_test_ops_task_status_machine_includes_async_states();
        },
            'OpsDeskTemplate::test_task_admin_template_hooks' => function () {
            $this->assert_OpsDeskTemplate_test_task_admin_template_hooks();
        },
        ];
    }

private function assert_OpsDeskTemplate_test_desk_template_has_unified_entry_hooks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/view/templates/backend/Desk/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="ops-desk-page"', $html);
    self::assertStringContainsString('data-testid="ops-desk-overview"', $html);
    self::assertStringContainsString('data-testid="ops-desk-config-bar"', $html);
    self::assertStringContainsString('data-testid="ops-desk-scopes"', $html);
    self::assertStringContainsString('data-testid="ops-desk-lanes"', $html);
    self::assertStringContainsString('data-testid="ops-desk-tasks"', $html);
    self::assertStringContainsString('data-testid="ops-desk-task-table"', $html);
    self::assertStringContainsString('data-testid="ops-open-persona"', $html);
    self::assertStringContainsString('data-testid="ops-open-dispatch"', $html);
    self::assertStringContainsString('data-testid="ops-refresh-persona"', $html);
    self::assertStringContainsString('data-testid="ops-persona-sse-panel"', $html);
    self::assertStringContainsString('w:theme:sse-progress', $html);
    self::assertStringContainsString('data-testid="ops-program-enabled-badge"', $html);
    self::assertStringContainsString('data-testid="ops-enable-program"', $html);
    self::assertStringContainsString("__('AI 运营默认关闭，需本页手动开启。开启后：画像、社媒/GSC/站内事件连接、任务中心与日更派工才生效。')", $html);
    self::assertStringContainsString('data-testid="ops-desk-persona"', $html);
    self::assertStringContainsString('data-w-component="dialog"', $html);
    self::assertStringContainsString('data-testid="ops-persona-structure"', $html);
    self::assertStringContainsString('data-testid="ops-persona-summary-block"', $html);
    self::assertStringContainsString("__('运营摘要（非站点简介原文）')", $html);
    $structurePos = strpos($html, 'ops-persona-structure');
    $summaryPos = strpos($html, 'ops-persona-summary-block');
    self::assertNotFalse($structurePos);
    self::assertNotFalse($summaryPos);
    self::assertLessThan($summaryPos, $structurePos, 'structure must render before summary');
    self::assertStringContainsString('data-testid="ops-desk-dispatch"', $html);
    self::assertStringContainsString('data-testid="ops-submit-config"', $html);
    self::assertStringContainsString('data-testid="ops-open-task-admin"', $html);
    // 页题由后台 chrome 输出；内容区禁止再写同名标题
    self::assertStringNotContainsString('w-backend-page__title', $html);
    self::assertStringNotContainsString('<h1', $html);
    self::assertStringContainsString('w-backend-page__subtitle', $html);
    // 站点选择在页头右侧（heading），不占「选择网站」大卡
    self::assertStringContainsString('data-testid="ops-desk-heading"', $html);
    $headingPos = strpos($html, 'data-testid="ops-desk-heading"');
    $formPos = strpos($html, 'data-testid="ops-desk-website-form"');
    $overviewPos = strpos($html, 'data-testid="ops-desk-overview"');
    $configPos = strpos($html, 'data-testid="ops-desk-config-bar"');
    $tasksPos = strpos($html, 'data-testid="ops-desk-tasks"');
    self::assertNotFalse($headingPos);
    self::assertNotFalse($formPos);
    self::assertNotFalse($overviewPos);
    self::assertNotFalse($configPos);
    self::assertNotFalse($tasksPos);
    self::assertLessThan($formPos, $headingPos);
    self::assertLessThan($overviewPos, $formPos);
    self::assertLessThan($configPos, $overviewPos);
    self::assertLessThan($tasksPos, $configPos);
    // 禁止旧「每车道嵌套大卡」墙：概览后不再出现独立 ops-desk-scope 大卡标题堆叠
    self::assertStringNotContainsString("__('连接与工作面')", $html);
    self::assertStringNotContainsString("__('任务中心')", $html);
    // 官方网站选择 Taglib：须带 options/display，筛选场景用 auto-submit
    self::assertStringContainsString('w:websites:website:select', $html);
    self::assertStringContainsString('options="websiteSelectOptionsJson"', $html);
    self::assertStringContainsString('display="websiteSelectDisplay"', $html);
    self::assertStringContainsString('auto-submit="true"', $html);
    self::assertStringContainsString('data-testid="ops-desk-website-form"', $html);
    self::assertStringNotContainsString("__('选择网站')", $html);
    self::assertStringNotContainsString('>' . "__('切换')" . '<', $html);
}

private function assert_OpsDeskTemplate_test_backend_menu_registers_ai_ops_group(): void
{
    $backendMenu = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Backend/etc/backend/menu.xml';
    $opsMenu = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/etc/backend/menu.xml';
    self::assertFileExists($backendMenu);
    self::assertFileExists($opsMenu);
    $backend = (string) file_get_contents($backendMenu);
    $ops = (string) file_get_contents($opsMenu);
    self::assertStringContainsString('Weline_Backend::ai_ops_group', $backend);
    self::assertStringContainsString('title="AI运营"', $backend);
    self::assertStringContainsString('parent="Weline_Backend::ai_ops_group"', $ops);
    self::assertStringContainsString('weline_ops/backend/desk/index', $ops);
    self::assertStringContainsString('weline_ops/backend/task/index', $ops);
    self::assertStringContainsString('title="调度任务管理"', $ops);
}

private function assert_OpsDeskTemplate_test_lane_contributions_exist_for_engines(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline';
    foreach (['Ops/extends/module/Weline_Ops/Lane/SiteLane.php', 'Ops/extends/module/Weline_Ops/Lane/ContentLane.php', 'Marketing/extends/module/Weline_Ops/Lane/GrowthLane.php', 'Social/extends/module/Weline_Ops/Lane/SocialLane.php', 'Seo/extends/module/Weline_Ops/Lane/SeoLane.php', 'Visitor/extends/module/Weline_Ops/Lane/VisitorLane.php', 'Promotion/extends/module/Weline_Ops/Lane/PromotionLane.php', 'Ops/extends/module/Weline_Ai/Agent/WebsiteOpsAgent.php', 'Ops/extends/module/Weline_Ai/Agent/OpsAnalystAgent.php', 'Ops/Queue/OpsTaskDispatchQueue.php', 'Ops/Controller/Backend/Task.php', 'Ops/Cron/OpsDailyDispatchCron.php', 'Blog/extends/module/Weline_Ai/Agent/BlogPublishAgent.php', 'Social/extends/module/Weline_Ai/Agent/SocialOpsAgent.php'] as $rel) {
        self::assertFileExists($root . '/' . $rel, $rel);
    }
}

private function assert_OpsDeskTemplate_test_ops_task_status_machine_includes_async_states(): void
{
    $statuses = \Weline\Ops\Model\OpsTask::allowedStatuses();
    foreach (['queued', 'running', 'awaiting_approval', 'done', 'failed', 'cancelled'] as $status) {
        self::assertContains($status, $statuses);
    }
    self::assertSame('ops_analyst', \Weline\Ops\Model\OpsTask::AGENT_OPS_ANALYST);
}

private function assert_OpsDeskTemplate_test_task_admin_template_hooks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/view/templates/backend/Task/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="ops-task-admin-page"', $html);
    self::assertStringContainsString('data-testid="ops-task-filters"', $html);
    self::assertStringContainsString('data-testid="ops-task-table"', $html);
    self::assertStringContainsString('data-testid="ops-task-tabs"', $html);
    self::assertStringContainsString('data-testid="ops-task-tab-tasks"', $html);
    self::assertStringContainsString('data-testid="ops-task-tab-logs"', $html);
    // 页题由后台 chrome 输出；模板内禁止再写同名 h1，避免双标题
    self::assertStringNotContainsString('w-heading', $html);
    self::assertStringNotContainsString('<h1', $html);
    self::assertStringContainsString('data-testid="ops-task-to-desk"', $html);
    // 宽表折叠：禁止把幂等键/全长会话并列为独立列头墙
    self::assertStringNotContainsString('<th>' . "__('幂等键')" . '</th>', $html);
    self::assertDoesNotMatchRegularExpression('/<th[^>]*>\s*<\?=\s*__\(\'幂等键\'\)/u', $html);
}
}
