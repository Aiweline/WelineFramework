<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 迷你购物车异步挂载契约：
 * 页面 SSR 只出挂载点，抽屉壳由带版本号的片段端点异步取回，并按版本号缓存在浏览器本地。
 */
final class MiniCartAsyncShellContractTest extends TestCase
{
    private function templateSource(): string
    {
        return (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml'
        );
    }

    private function jsSource(): string
    {
        return (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js'
        );
    }

    public function testTemplateGatesDrawerBehindShellMode(): void
    {
        $src = $this->templateSource();
        self::assertStringContainsString('$shellMode = (bool)($this->getData(\'shell_mode\') ?? false);', $src);
        self::assertStringContainsString('data-mini-cart-shell-host', $src);
        self::assertStringContainsString('data-mini-cart-shell-version', $src);
        // 抽屉标记必须整体位于 shell_mode 分支内：页面模式不得下发。
        $shellPos = \strpos($src, 'if ($shellMode) {');
        $drawerPos = \strpos($src, 'id="mini-cart-drawer"');
        $returnPos = \strpos($src, 'return;', $shellPos === false ? 0 : $shellPos);
        self::assertNotFalse($shellPos);
        self::assertNotFalse($drawerPos);
        self::assertNotFalse($returnPos);
        self::assertLessThan($drawerPos, $shellPos);
        self::assertLessThan($returnPos, $drawerPos);
    }

    public function testScriptMountsShellByVersionedLocalCache(): void
    {
        $js = $this->jsSource();
        self::assertStringContainsString("var shellCachePrefix = 'weline.mini-cart.shell.v1:';", $js);
        self::assertStringContainsString('function mountShell(root)', $js);
        self::assertStringContainsString("var base = '/theme/frontend/widget/shell';", $js);
        self::assertStringContainsString('data-mini-cart-shell-version', $js);
        // 本地命中直接挂载，不再请求空壳。
        self::assertStringContainsString('var cached = readShellCache(key);', $js);
        self::assertStringContainsString('if (cached && applyShellHtml(host, cached)) {', $js);
        // 版本变化清理旧键。
        self::assertStringContainsString('pruneShellCache(scope, key);', $js);
        // 壳未挂载时先取壳再开抽屉。
        self::assertStringContainsString('data-mini-cart-shell-pending', $js);
    }

    /**
     * 默认部件也必须「点击才水合」：启动阶段只绑徽标/摘要，不得预取抽屉壳，
     * 否则水合后的壳会在首屏参与文档流（2026-11-03 实拍复现）。
     */
    public function testBootDoesNotHydrateShellBeforeInteraction(): void
    {
        $js = $this->jsSource();
        $bootStart = \strpos($js, 'function bootMiniCartRoots(options)');
        self::assertNotFalse($bootStart);
        $bootEnd = \strpos($js, 'function observeMiniCartRoots()', $bootStart);
        self::assertNotFalse($bootEnd);
        $boot = \substr($js, $bootStart, $bootEnd - $bootStart);
        self::assertStringNotContainsString('mountShell(root)', $boot, 'boot 阶段禁止预水合抽屉壳');
        // 声明式展开：任意部件声明 data-mini-cart-open 即可展开。
        self::assertStringContainsString("var openRequestAttr = 'data-mini-cart-open';", $js);
        self::assertStringContainsString('function bindDeclarativeOpen()', $js);
        self::assertStringContainsString('bindDeclarativeOpen();', $js);
    }

    public function testDefaultWidgetDeclaresDeclarativeOpen(): void
    {
        $src = $this->templateSource();
        self::assertStringContainsString('data-mini-cart-open="toggle"', $src);
        self::assertStringNotContainsString('data-mini-cart-trigger', $src);
    }

    public function testShellEndpointOnlyServesWhitelistedNeutralWidget(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Frontend/Widget/Shell.php'
        );
        self::assertStringContainsString("'mini-cart-icon' => ['Weline_Theme', 'header']", $src);
        self::assertStringContainsString("'shell_mode' => true", $src);
        // 片段走 JSON（{html: …}）：PC 前台 HTML 文档响应会被 CSP/开发面板等文档级注入装饰。
        self::assertStringContainsString('application/json; charset=utf-8', $src);
        self::assertStringContainsString("['html' => \$html]", $src);
        self::assertStringNotContainsString('cart_summary', $src);
    }
}
