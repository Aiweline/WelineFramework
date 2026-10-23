<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Weline.UI dialog 同任务 close → open 契约：
 *
 * 原生 `close` 事件晚一个任务派发。若同任务内先 close() 再 showModal()，旧 close 处理器会把
 * 已重新打开的 dialog 写成 hidden，留下不可见的 modal 把整页 inert（迷你购物车 / 快速查看重开时复现）。
 * 源码与构建产物须同时具备：overlay 栈按元素幂等、close 处理器在 dialog 仍 open 时放弃收尾。
 */
final class WelineUiDialogReopenContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function runtimeFiles(): iterable
    {
        yield 'source' => ['app/code/Weline/Theme/view/ui/js/weline-ui.js'];
        yield 'bundle' => ['app/code/Weline/Theme/view/statics/ui/weline-ui.js'];
    }

    /**
     * @dataProvider runtimeFiles
     */
    public function testSameTaskReopenKeepsDialogVisible(string $relative): void
    {
        $js = (string) \file_get_contents(BP . $relative);
        self::assertNotSame('', $js, $relative);

        $push = \strpos($js, 'function pushOverlay(element, restoreFocus)');
        self::assertNotFalse($push, $relative);
        $pushBody = \substr($js, $push, 600);
        self::assertStringContainsString('overlayStack.findIndex((entry) => entry.element === element)', $pushBody, $relative);
        self::assertStringContainsString('overlayStack.splice(existing, 1)', $pushBody, $relative);

        self::assertMatchesRegularExpression(
            "/listen\\(element, 'close', \\(\\) => \\{[^}]*?if \\(element\\.open\\) return;\\s*element\\.dataset\\.state = 'closed';/s",
            $js,
            $relative,
        );
    }
}
