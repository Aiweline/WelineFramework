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
}
