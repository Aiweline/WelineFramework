<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 模型列表页脚本被压成极少行；行注释 // 会吃掉同行后续全部代码，导致连通测试等动作完全无绑定。
 */
final class ModelIndexScriptContractTest extends TestCase
{
    private function scriptSource(): string
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Model/index.phtml';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        $start = strpos($html, "(function() { 'use strict';");
        self::assertNotFalse($start, 'model index IIFE missing');
        $end = strpos($html, '</script>', $start);
        self::assertNotFalse($end, 'model index script close missing');

        return substr($html, $start, $end - $start);
    }

    public function test_model_index_wires_connectivity_test_hooks(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Model/index.phtml';
        $html = (string)file_get_contents($path);
        self::assertStringContainsString('data-model-action="test-provider"', $html);
        self::assertStringContainsString('window.testModelConnection', $html);
        self::assertStringContainsString("case 'test-provider':", $html);
        self::assertStringContainsString("apiRequest('modelTestConnection'", $html);
        // 模型行连通必须直测点击的 model_code，禁止再弹供应商侧「选择测试模型」
        self::assertStringNotContainsString("title: '选择测试模型'", $html);
        self::assertStringNotContainsString('chooseProviderTestModel', $html);
        self::assertStringContainsString("{model_code: modelCode}", $html);
    }

    public function test_model_index_script_forbids_line_comments_in_packed_iife(): void
    {
        $script = $this->scriptSource();
        // 允许 URL 中的 ://；禁止其它 // 行注释（压成单行时会注释掉后续全部逻辑）
        self::assertSame(
            0,
            preg_match('/(?<!:)\\/\\//', $script),
            'Packed model index IIFE must not contain // line comments; use /* */ instead.'
        );
        self::assertStringContainsString('/* 保留 bin-query', $script);
        self::assertStringContainsString('/* 批量选择功能 */', $script);
    }
}
