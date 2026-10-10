<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * WLS 面板后台内嵌宿主：菜单指向 host、模板含 iframe/模式切换、控制器赋 frameUrl。
 */
final class WlsPanelHostEmbedContractTest extends TestCase
{
    private function moduleRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testMenuPointsToHostAction(): void
    {
        $path = $this->moduleRoot() . '/etc/backend/menu.xml';
        self::assertFileExists($path);
        $document = \simplexml_load_file($path);
        self::assertNotFalse($document);
        $action = null;
        foreach ($document->menu as $menu) {
            if ((string)$menu['name'] === 'wls_panel') {
                $action = (string)$menu['action'];
                break;
            }
        }
        self::assertSame('*/backend/wls-panel/host', $action);
    }

    public function testHostTemplateHasIframeAndModeHooks(): void
    {
        $path = $this->moduleRoot() . '/view/templates/Backend/WlsPanel/host.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('data-testid="wls-panel-host-frame"', $src);
        self::assertStringContainsString('data-wls-panel-host-shell', $src);
        self::assertStringContainsString('data-mode="float"', $src);
        self::assertStringContainsString('data-wls-panel-host-expand', $src);
        self::assertStringContainsString('data-wls-panel-host-collapse', $src);
        self::assertStringContainsString('wls.panel.host.mode', $src);
        self::assertStringContainsString("setMode('float')", $src);
        self::assertStringContainsString('wlsPanelHostFrameUrl', $src);
        self::assertStringContainsString('wlsPanelHostStandaloneUrl', $src);
        self::assertStringContainsString('z-index: 13000', $src);
        self::assertStringContainsString('body:has(.wls-panel-host-shell[data-mode="float"])', $src);
        // 默认布局已渲染 H1 页标题；宿主工具条禁止再叠同名 h1/h2/h3/h4
        self::assertDoesNotMatchRegularExpression(
            '/<h[1-4][^>]*>\s*<\?=\s*__\(\s*[\'"]WLS 面板[\'"]\s*\)/u',
            $src
        );
    }

    public function testControllerAssignsFrameUrlWithEmbeddedFlag(): void
    {
        $path = $this->moduleRoot() . '/Controller/Backend/WlsPanel.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('function getHost()', $src);
        self::assertStringContainsString("getBackendUrl('*/backend/wls-panel', ['embedded' => '1'])", $src);
        self::assertStringContainsString("assign('wlsPanelHostFrameUrl'", $src);
        self::assertStringContainsString("assign('wlsPanelHostStandaloneUrl'", $src);
        self::assertStringContainsString('function isEmbeddedPanelRequest()', $src);
        self::assertStringContainsString("assign('panelEmbedded'", $src);
        self::assertDoesNotMatchRegularExpression(
            '/function getHost\(\): string\s*\{[^}]*useStandaloneLayout\(\)/s',
            $src
        );
    }

    public function testStandaloneShellRecognizesEmbeddedClass(): void
    {
        $path = $this->moduleRoot() . '/view/templates/Backend/WlsPanel/index.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("panelEmbedded ? ' is-embedded' : ''", $src);
        self::assertStringContainsString('.wls-standalone-shell.is-embedded', $src);
        self::assertStringContainsString('.wls-standalone-shell.is-embedded .wls-shell-main', $src);
        self::assertStringContainsString('grid-template-rows: minmax(0, 1fr)', $src);
        self::assertStringContainsString('overflow-y: auto', $src);
        self::assertStringContainsString('min-height: 0', $src);
        self::assertStringContainsString('.w-backend-standalone', $src);
        self::assertStringContainsString('#main-content', $src);
    }
}
