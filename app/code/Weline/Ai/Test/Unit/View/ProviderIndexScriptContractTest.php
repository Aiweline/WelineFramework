<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 供应商账户页连通测试弹层必须走 Weline native dialog 顶层；
 * Bootstrap .modal 落在文档流底部时视口外可见性=0，表现为「点击测试没反应」。
 */
final class ProviderIndexScriptContractTest extends TestCase
{
    private function scriptSource(): string
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Provider/index.phtml';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        $start = strpos($html, "(function() {\n    'use strict';");
        if ($start === false) {
            $start = strpos($html, "(function() {");
        }
        self::assertNotFalse($start, 'provider index IIFE missing');
        $end = strpos($html, '</script>', $start);
        self::assertNotFalse($end, 'provider index script close missing');

        return substr($html, $start, $end - $start);
    }

    public function test_provider_index_wires_connectivity_test_hooks(): void
    {
        $html = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Provider/index.phtml');
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

    public function test_provider_index_forbids_bootstrap_flow_modals_for_test_feedback(): void
    {
        $script = $this->scriptSource();
        self::assertStringNotContainsString("className = 'modal fade show'", $script);
        self::assertStringNotContainsString('modal-dialog modal-dialog-centered', $script);
        self::assertStringContainsString("createElement('dialog')", $script);
        self::assertStringContainsString('Weline.UI.dialog.open', $script);
        self::assertStringContainsString('providerTestBusyDialog', $script);
    }

    public function test_provider_index_surfaces_connection_test_failure_details(): void
    {
        $script = $this->scriptSource();
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

    public function test_provider_index_auto_picks_latest_test_model_without_select_dialog(): void
    {
        $script = $this->scriptSource();
        self::assertStringContainsString('function resolveProviderTestModel', $script);
        self::assertStringContainsString('default_model_code', $script);
        self::assertStringContainsString('resolveProviderTestModel(getAccountById(id))', $script);
        self::assertStringNotContainsString("title: '选择测试模型'", $script);
        self::assertStringNotContainsString('function chooseProviderTestModel', $script);
    }

    public function test_provider_index_keeps_nested_payload_for_test_and_save(): void
    {
        $script = $this->scriptSource();
        self::assertStringContainsString('function buildProviderAccountPayload', $script);
        self::assertStringContainsString("return { payload: body };", $script);
        self::assertStringContainsString('Unknown frontend worker param: id', $script);
        // 禁止再把 payload 展平到顶层（旧逻辑：payload.payload || payload）
        self::assertStringNotContainsString('payload.payload || payload', $script);
    }
}
