<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\DarwinTerminalWindow;

final class DarwinTerminalWindowTest extends TestCase
{
    public function testEscapeAppleScriptStringEscapesBackslashAndQuote(): void
    {
        self::assertSame('a\\\\b\\"c', DarwinTerminalWindow::escapeAppleScriptString('a\\b"c'));
    }

    public function testBuildWrapperScriptBodyUsesTailFollowAndEscapesSingleQuotes(): void
    {
        $body = DarwinTerminalWindow::buildWrapperScriptBody("Worker'test", "/tmp/a'b.log", "/tmp/x.pid");

        self::assertStringContainsString("#!/bin/bash\n", $body);
        self::assertStringContainsString("echo $$ > '/tmp/x.pid'\n", $body);
        self::assertStringContainsString("exec tail -n 200 -F '", $body);
        self::assertStringContainsString("a'\\''b.log", $body);
        self::assertStringContainsString("Worker'\\''test", $body);
    }

    public function testBuildTerminalDoScriptAppleScriptReturnsWindowId(): void
    {
        $script = DarwinTerminalWindow::buildTerminalDoScriptAppleScript(
            '/tmp/weline-wls-win-tail-abcd.sh',
            'worker#1'
        );

        self::assertStringContainsString('tell application "Terminal"', $script);
        self::assertStringContainsString('do script "bash " & quoted form of "/tmp/weline-wls-win-tail-abcd.sh"', $script);
        self::assertStringContainsString('set custom title of front window to "worker#1"', $script);
        self::assertStringContainsString('return id of front window', $script);
        self::assertStringNotContainsString('框架', $script);
    }

    public function testBuildCloseWindowAppleScriptTargetsNumericId(): void
    {
        $script = DarwinTerminalWindow::buildCloseWindowAppleScript(4242);
        self::assertStringContainsString('close (every window whose id is 4242)', $script);
    }

    public function testBuildCloseWindowsByTitleNeedleAppleScriptContainsBothNeedles(): void
    {
        $script = DarwinTerminalWindow::buildCloseWindowsByTitleNeedleAppleScript('weline-wls-', 'p05113ef3');
        self::assertStringContainsString('t contains "weline-wls-"', $script);
        self::assertStringContainsString('t contains "p05113ef3"', $script);
        self::assertStringContainsString('close w', $script);
    }

    public function testOpenLogFollowIsNoopOnNonDarwin(): void
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            self::markTestSkipped('Non-Darwin no-op path only.');
        }

        $result = DarwinTerminalWindow::openLogFollow('title', '/tmp/x.log');
        self::assertFalse($result['ok']);
        self::assertSame(0, $result['window_id']);
    }
}
