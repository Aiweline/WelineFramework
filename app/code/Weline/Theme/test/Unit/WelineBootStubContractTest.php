<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * head 核心脚本 defer 化契约。
 *
 * 目标：head 内不再有「无 defer/async/module」的外部脚本 —— 靠三件事：
 *   1) 启动桩（weline-boot-stub.phtml）把 declare/load/preLoad/mount/dom.observe 变成队列；
 *   2) weline.js 初始化后回放队列；
 *   3) prepaint 内联（必须首绘前同步执行）+ 其余脚本 defer。
 *
 * 这些是源码字面量契约：改动任一处都要同步本测试，防止静默回退成阻塞。
 */
final class WelineBootStubContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
        self::assertFileExists($path, $relative . ' 不存在');

        return (string)file_get_contents($path);
    }

    private function readWeline(string $moduleRelative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($moduleRelative, '/');
        self::assertFileExists($path, $moduleRelative . ' 不存在');

        return (string)file_get_contents($path);
    }

    public function testBootStubQueuesAsyncApis(): void
    {
        $stub = $this->read('view/theme/frontend/partials/head/weline-boot-stub.phtml');
        self::assertStringContainsString('__bootQueue', $stub);
        self::assertStringContainsString('__bootStubInstalled', $stub);
        self::assertStringContainsString('__welineBootStub', $stub);
        foreach (['declare', 'load', 'preLoad', 'mount', 'observeMutationsCoalesced'] as $api) {
            self::assertStringContainsString("'" . $api . "'", $stub, '桩缺少 API: ' . $api);
        }
        self::assertStringContainsString("'dom.' + api", $stub);
        // env 必须提前抢出来，否则 body 内联脚本读到的 window.DEV 会变。
        self::assertStringContainsString('window.DEV', $stub);
        self::assertStringContainsString('data-weline-runtime-config', $stub);
    }

    public function testPrefixMountsStubBeforeFoucBootstrap(): void
    {
        $prefix = $this->read('view/theme/frontend/partials/head/assets-prefix.phtml');
        $stubPos = strpos($prefix, 'weline-boot-stub.phtml');
        $foucPos = strpos($prefix, 'css-fouc-bootstrap.phtml');
        self::assertNotFalse($stubPos, '前缀段未挂载启动桩');
        self::assertNotFalse($foucPos);
        self::assertLessThan($foucPos, $stubPos, '启动桩必须排在 FOUC 引导之前');
        // prepaint 改内联
        self::assertStringContainsString('tryInlineScriptHtml', $prefix);
    }

    public function testWelineJsDrainsBootQueue(): void
    {
        $js = $this->readWeline('Frontend/view/statics/js/weline.js');
        self::assertStringContainsString('__bootQueue', $js);
        self::assertStringContainsString('drainBootQueue', $js);
        // 回放时要跳过仍是桩的目标，避免二次入队
        self::assertStringContainsString('__welineBootStub', $js);
    }

    public function testDomObserverIgnoresBootStub(): void
    {
        $js = $this->readWeline('Framework/view/statics/js/dom-observer.js');
        self::assertStringContainsString('__welineBootStub', $js);
    }

    public function testCoreHeadScriptsAreDeferred(): void
    {
        $head = $this->readWeline('Frontend/view/blocks/header/base.phtml');
        foreach ([
            '<js defer>Weline_Frontend::js/runtime.js</js>',
            '<js defer>Weline_Framework::js/dom-observer.js</js>',
            '<js defer>Weline_Frontend::js/weline-api-business.js</js>',
            '<js defer>Weline_Framework::js/url-frontend.js</js>',
        ] as $needle) {
            self::assertStringContainsString($needle, $head);
        }
        // 裸 script 的 weline.js 也要 defer
        self::assertMatchesRegularExpression('/<script defer src="<\?= htmlspecialchars\(\$welineJsSrc/', $head);
    }

    public function testJsPackSupportsInlineAndDefer(): void
    {
        $pack = $this->read('Service/ThemeHeadChromeJsPack.php');
        self::assertStringContainsString('public function tryInlineScriptHtml', $pack);
        self::assertStringContainsString('public function tryScriptHtml', $pack);
        self::assertStringContainsString('bool $defer = false', $pack);
        self::assertStringContainsString("' defer'", $pack);
        // 内联正文必须防 </script> 提前闭合
        self::assertStringContainsString('</script', $pack);
    }
}
