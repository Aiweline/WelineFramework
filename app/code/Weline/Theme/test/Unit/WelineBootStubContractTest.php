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
        $stub = $this->readWeline('Frontend/view/blocks/header/base.phtml');
        self::assertStringContainsString('data-weline-boot-stub', $stub);
        self::assertStringContainsString('__bootQueue', $stub);
        self::assertStringContainsString('__bootStubInstalled', $stub);
        self::assertStringContainsString('__welineBootStub', $stub);
        foreach (['declare', 'load', 'preLoad', 'mount', 'observeMutationsCoalesced'] as $api) {
            self::assertStringContainsString("'" . $api . "'", $stub, '桩缺少 API: ' . $api);
        }
        self::assertStringContainsString("'dom.' + api", $stub);
        // env 必须提前给出，否则 body 内联脚本读到的 window.DEV 会变。
        self::assertStringContainsString('window.DEV', $stub);
        self::assertStringContainsString('window.WELINE_ENV', $stub);
        self::assertStringContainsString("defined('DEV')", $stub);
    }

    public function testStubIsCoLocatedWithDeferredScripts(): void
    {
        // 桩必须与 defer 脚本同处 base.phtml：不覆盖主题 head 局部的主题也才有桩。
        $stub = $this->readWeline('Frontend/view/blocks/header/base.phtml');
        $stubPos = strpos($stub, 'data-weline-boot-stub');
        $runtimePos = strpos($stub, '<js defer>Weline_Frontend::js/runtime.js</js>');
        self::assertNotFalse($stubPos, 'base.phtml 缺少启动桩');
        self::assertNotFalse($runtimePos, 'base.phtml 缺少 defer 的 runtime.js');
        self::assertLessThan($runtimePos, $stubPos, '启动桩必须排在 defer 脚本之前');
        // Default Theme Amazon-style：前缀段仅 CSS，Theme chrome JS → modules / body-end。
        $prefix = $this->read('view/theme/frontend/partials/head/assets-prefix.phtml');
        self::assertStringNotContainsString('weline-boot-stub', $prefix);
        self::assertStringNotContainsString('tryInlineScriptHtml', $prefix);
        self::assertStringNotContainsString('ThemeHeadChromeJsPack', $prefix);
        self::assertStringNotContainsString('<script', $prefix);
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
