<?php

declare(strict_types=1);

use LearningMcp\McpSkillCatalog;

require dirname(__DIR__) . '/src/bootstrap.php';

/**
 * 技能路由金标集回归（skill-routing-accuracy.v1）
 *
 * 目的：锁住「用户原话 → 技能集」的首轮命中率。基线为 2026-11-05 改造前实测：
 * 复合需求只命中零散泛词，23 条原话中 11 条 0 命中。
 *
 * 判定：
 *   - must   ：期望技能必须出现在 top-6（首轮覆盖）
 *   - primary：期望技能必须是第 1 名（仅在归属无歧义时声明）
 *   - forbid ：负向用例，这些技能不得出现（exclude_triggers 生效）
 *   - lane   ：内容运营车道，MCP 不提供工作流技能（应返回空）
 */

$limit = 6;

$cases = [
    // ---- 前端 / 主题 / 部件 ----
    [
        'task' => '改结算页按钮颜色和间距',
        'must' => ['frontend_development', 'design_taste'],
        'primary' => 'frontend_development',
    ],
    [
        'task' => '首页主题换成新的品牌色',
        'must' => ['frontend_development', 'design_taste'],
        'primary' => 'design_taste',
    ],
    [
        'task' => '新建一个商品列表部件',
        'must' => ['widget_development', 'frontend_development'],
    ],
    [
        'task' => '给商品页加一个规格图轮播',
        'must' => ['widget_development', 'frontend_development'],
        'primary' => 'widget_development',
    ],
    // ---- 后台 UI / Taglib / 升级门禁 ----
    [
        'task' => '新增一个订单状态字段并在后台列表显示',
        'must' => ['frontend_development', 'taglib_ui_control', 'module_upgrade_gate', 'webui_browser_closeout'],
    ],
    [
        'task' => '后台列表要加个筛选器',
        'must' => ['frontend_development', 'taglib_ui_control'],
    ],
    // ---- i18n ----
    [
        'task' => '把这个模板里的文案改成英文',
        'must' => ['template_i18n', 'module_i18n_csv', 'frontend_development'],
    ],
    [
        'task' => '补一下模块的翻译 CSV',
        'must' => ['module_i18n_csv', 'template_i18n'],
        'primary' => 'module_i18n_csv',
    ],
    [
        'task' => '这个页面英文环境下露出中文了',
        'must' => ['translation_engineer', 'module_i18n_csv'],
        'primary' => 'translation_engineer',
    ],
    // ---- 后端 / 扩展点 / API ----
    [
        'task' => '新增一个 Hook 打印订单日志',
        'must' => ['hook_extension'],
        'primary' => 'hook_extension',
    ],
    [
        'task' => '加个事件监听器同步库存',
        'must' => ['event_extension'],
        'primary' => 'event_extension',
    ],
    [
        'task' => '给第三方开放下单 API',
        'must' => ['api_sdk_development'],
        'primary' => 'api_sdk_development',
    ],
    // ---- 业务域 ----
    [
        'task' => '给结算页加个支付方式',
        'must' => ['payment_development'],
        'primary' => 'payment_development',
    ],
    [
        'task' => '帮我看下这单为什么没付款',
        'must' => ['payment_development'],
        'primary' => 'payment_development',
    ],
    [
        'task' => '统计访客转化率做报表',
        'must' => ['visitor_data_analytics'],
        'primary' => 'visitor_data_analytics',
    ],
    // ---- 流程 / 验收 / 运维 ----
    [
        'task' => '需求还不清楚，先澄清一下',
        'must' => ['requirement_clarify_use_case'],
        'primary' => 'requirement_clarify_use_case',
    ],
    [
        'task' => '审一下这段代码的架构边界',
        'must' => ['code_review'],
        'primary' => 'code_review',
    ],
    [
        'task' => '这个页面加载很慢要优化',
        'must' => ['performance_check'],
        'primary' => 'performance_check',
    ],
    [
        'task' => '把这段提示词压缩一下',
        'must' => ['prompt_optimization'],
        'primary' => 'prompt_optimization',
    ],
    [
        'task' => '新增一条硬规则并登记到语言表',
        'must' => ['hc_sl_rule_lifecycle'],
        'primary' => 'hc_sl_rule_lifecycle',
    ],
    [
        'task' => '线上接口 502 了，帮我探活',
        'must' => ['production_http_probe'],
        'primary' => 'production_http_probe',
    ],
    // ---- 负向用例：明确说不要翻译，i18n 族永不进场 ----
    [
        'task' => '改按钮颜色，不要翻译',
        'must' => ['frontend_development'],
        'forbid' => ['module_i18n_csv', 'template_i18n', 'translation_engineer'],
    ],
    // ---- 内容运营车道：MCP 不提供工作流技能（走 dev/ai-command/ai/新建文章）----
    [
        'task' => '给博客文章加封面图并发布',
        'lane' => 'content_ops',
        'expect_empty' => true,
    ],
];

$failed = false;
$checks = [];
$caseReports = [];

function routingCheck(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks[] = ['label' => $label, 'passed' => $ok];
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

foreach ($cases as $case) {
    $task = (string) $case['task'];
    $rows = McpSkillCatalog::resolve($task, $limit, false, '', false);
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (string) $row['skill_id'];
    }
    $report = ['task' => $task, 'top' => $ids, 'passed' => true];

    if (!empty($case['expect_empty'])) {
        $ok = $ids === [];
        routingCheck($ok, '车道 ' . ($case['lane'] ?? '?') . ' 不返回工作流技能：' . $task);
        $report['passed'] = $ok;
        $caseReports[] = $report;
        continue;
    }

    $missing = [];
    foreach ($case['must'] ?? [] as $expected) {
        if (!in_array($expected, $ids, true)) {
            $missing[] = $expected;
        }
    }
    $okMust = $missing === [];
    routingCheck($okMust, '首轮覆盖 ' . $task . (($okMust) ? '' : '（缺：' . implode(', ', $missing) . '；实际：' . implode(', ', $ids) . '）'));
    $report['passed'] = $report['passed'] && $okMust;

    if (isset($case['primary'])) {
        $primary = (string) $case['primary'];
        $actualPrimary = $ids[0] ?? '';
        $okPrimary = $actualPrimary === $primary;
        routingCheck($okPrimary, '首位归属 ' . $task . '（期望 ' . $primary . '，实际 ' . ($actualPrimary !== '' ? $actualPrimary : '无') . '）');
        $report['passed'] = $report['passed'] && $okPrimary;
    }

    if (!empty($case['forbid'])) {
        $leaked = [];
        foreach ($case['forbid'] as $forbidden) {
            if (in_array($forbidden, $ids, true)) {
                $leaked[] = $forbidden;
            }
        }
        $okForbid = $leaked === [];
        routingCheck($okForbid, '负向排除 ' . $task . (($okForbid) ? '' : '（误入：' . implode(', ', $leaked) . '）'));
        $report['passed'] = $report['passed'] && $okForbid;
    }

    $caseReports[] = $report;
}

$total = count($caseReports);
$passedCases = count(array_filter($caseReports, static fn (array $c): bool => $c['passed'] === true));
$coverage = $total > 0 ? $passedCases / $total : 0.0;
routingCheck($coverage >= 0.9, sprintf('首轮覆盖率 >= 90%%（实际 %.1f%%，%d/%d）', $coverage * 100, $passedCases, $total));

fwrite(STDOUT, json_encode([
    'schema_version' => 'skill-routing-accuracy.v1',
    'passed' => !$failed,
    'coverage' => round($coverage, 4),
    'cases' => $caseReports,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($failed ? 1 : 0);
