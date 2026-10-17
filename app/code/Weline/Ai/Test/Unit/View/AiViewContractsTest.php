<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllAiViewContractsPass().
 */
final class AiViewContractsTest extends TestCase
{
    public function testAllAiViewContractsPass(): void
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
            'ModelIndexScript::test_model_index_wires_connectivity_test_hooks' => function () {
            $this->assert_ModelIndexScript_test_model_index_wires_connectivity_test_hooks();
        },
            'ModelIndexScript::test_model_index_script_forbids_line_comments_in_packed_iife' => function () {
            $this->assert_ModelIndexScript_test_model_index_script_forbids_line_comments_in_packed_iife();
        },
            'ProviderIndexScript::test_provider_index_wires_connectivity_test_hooks' => function () {
            $this->assert_ProviderIndexScript_test_provider_index_wires_connectivity_test_hooks();
        },
            'ProviderIndexScript::test_provider_index_forbids_bootstrap_flow_modals_for_test_feedback' => function () {
            $this->assert_ProviderIndexScript_test_provider_index_forbids_bootstrap_flow_modals_for_test_feedback();
        },
            'ProviderIndexScript::test_provider_index_surfaces_connection_test_failure_details' => function () {
            $this->assert_ProviderIndexScript_test_provider_index_surfaces_connection_test_failure_details();
        },
            'ProviderIndexScript::test_provider_index_auto_picks_latest_test_model_without_select_dialog' => function () {
            $this->assert_ProviderIndexScript_test_provider_index_auto_picks_latest_test_model_without_select_dialog();
        },
            'ProviderIndexScript::test_provider_index_keeps_nested_payload_for_test_and_save' => function () {
            $this->assert_ProviderIndexScript_test_provider_index_keeps_nested_payload_for_test_and_save();
        },
        ];
    }

private function scriptSource_ModelIndexScript(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/view/templates/backend/Model/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    $start = strpos($html, "(function() { 'use strict';");
    self::assertNotFalse($start, 'model index IIFE missing');
    $end = strpos($html, '</script>', $start);
    self::assertNotFalse($end, 'model index script close missing');
    return substr($html, $start, $end - $start);
}

private function assert_ModelIndexScript_test_model_index_wires_connectivity_test_hooks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/view/templates/backend/Model/index.phtml';
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('data-model-action="test-provider"', $html);
    self::assertStringContainsString('window.testModelConnection', $html);
    self::assertStringContainsString("case 'test-provider':", $html);
    self::assertStringContainsString("apiRequest('modelTestConnection'", $html);
    // 模型行连通必须直测点击的 model_code，禁止再弹供应商侧「选择测试模型」
    self::assertStringNotContainsString("title: '选择测试模型'", $html);
    self::assertStringNotContainsString('chooseProviderTestModel', $html);
    self::assertStringContainsString("{model_code: modelCode}", $html);
}

private function assert_ModelIndexScript_test_model_index_script_forbids_line_comments_in_packed_iife(): void
{
    $script = $this->scriptSource_ModelIndexScript();
    // 允许 URL 中的 ://；禁止其它 // 行注释（压成单行时会注释掉后续全部逻辑）
    self::assertSame(0, preg_match('/(?<!:)\/\//', $script), 'Packed model index IIFE must not contain // line comments; use /* */ instead.');
    self::assertStringContainsString('/* 保留 bin-query', $script);
    self::assertStringContainsString('/* 批量选择功能 */', $script);
}

private function scriptSource_ProviderIndexScript(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/view/templates/backend/Provider/index.phtml';
    self::assertFileExists($path);
    $html = (string) file_get_contents($path);
    $start = strpos($html, "(function() {\n    'use strict';");
    if ($start === false) {
        $start = strpos($html, "(function() {");
    }
    self::assertNotFalse($start, 'provider index IIFE missing');
    $end = strpos($html, '</script>', $start);
    self::assertNotFalse($end, 'provider index script close missing');
    return substr($html, $start, $end - $start);
}

private function assert_ProviderIndexScript_test_provider_index_wires_connectivity_test_hooks(): void
{
    $html = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ai' . '/view/templates/backend/Provider/index.phtml');
    self::assertStringContainsString('data-provider-action="test"', $html);
    self::assertStringContainsString('window.testConnection', $html);
    self::assertStringContainsString('openProviderBusyDialog', $html);
    self::assertStringContainsString('providerTestResultDialog', $html);
    self::assertStringContainsString("providerAccountCall('testConnection'", $html);
    self::assertStringContainsString("__('账户')", $html);
    self::assertStringContainsString("__('密钥与费用')", $html);
    self::assertStringContainsString('colspan="4"', $html);
    self::assertStringNotContainsString('<th>ID</th>', $html);
    self::assertStringNotContainsString('<th>供应商</th>', $html);
}

private function assert_ProviderIndexScript_test_provider_index_forbids_bootstrap_flow_modals_for_test_feedback(): void
{
    $script = $this->scriptSource_ProviderIndexScript();
    self::assertStringNotContainsString("className = 'modal fade show'", $script);
    self::assertStringNotContainsString('modal-dialog modal-dialog-centered', $script);
    self::assertStringContainsString("createElement('dialog')", $script);
    self::assertStringContainsString('Weline.UI.dialog.open', $script);
    self::assertStringContainsString('providerTestBusyDialog', $script);
}

private function assert_ProviderIndexScript_test_provider_index_surfaces_connection_test_failure_details(): void
{
    $script = $this->scriptSource_ProviderIndexScript();
    self::assertStringContainsString("api.resource('ai')", $script);
    self::assertStringContainsString('providerTestConnection', $script);
    self::assertStringContainsString('keepBusinessResult: true', $script);
    self::assertStringContainsString('formatProviderConnectionTestDetail', $script);
    self::assertStringContainsString('extractProviderAccountFailurePayload', $script);
    self::assertStringContainsString("__('请求地址')", $script);
    self::assertStringContainsString("__('基础 URL')", $script);
    self::assertStringContainsString("__('密钥尾号')", $script);
    self::assertStringNotContainsString("__('测试失败: 网络错误')", $script);
    self::assertStringNotContainsString('api.request(url, options)', $script);
}

private function assert_ProviderIndexScript_test_provider_index_auto_picks_latest_test_model_without_select_dialog(): void
{
    $script = $this->scriptSource_ProviderIndexScript();
    self::assertStringContainsString('function resolveProviderTestModel', $script);
    self::assertStringContainsString('default_model_code', $script);
    self::assertStringContainsString('resolveProviderTestModel(getAccountById(id))', $script);
    self::assertStringNotContainsString("title: '选择测试模型'", $script);
    self::assertStringNotContainsString('function chooseProviderTestModel', $script);
}

private function assert_ProviderIndexScript_test_provider_index_keeps_nested_payload_for_test_and_save(): void
{
    $script = $this->scriptSource_ProviderIndexScript();
    self::assertStringContainsString('function buildProviderAccountPayload', $script);
    self::assertStringContainsString("return { payload: body };", $script);
    self::assertStringContainsString('Unknown frontend worker param: id', $script);
    // 禁止再把 payload 展平到顶层（旧逻辑：payload.payload || payload）
    self::assertStringNotContainsString('payload.payload || payload', $script);
}
}
