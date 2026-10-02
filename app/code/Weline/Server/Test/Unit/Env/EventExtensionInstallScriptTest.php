<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Env;

use PHPUnit\Framework\TestCase;

/**
 * WLS 模块必须自带能真正装好 ext-event 的 env 安装脚本。
 *
 * 背景：Linux 下 WLS 默认走 Direct；Direct 需要 ext-event（shared_fd）或
 * SO_REUSEPORT。ext-event 缺失时 WLS 会退化为 Dispatcher 模式 —— 多一个进程、
 * 多一个故障点。原脚本只覆盖 pecl 与发行版包两条路径，而 bundled PHP 往往
 * 既没有 pecl、也没有加载的 php.ini，导致 ext-event 实际装不上。
 */
final class EventExtensionInstallScriptTest extends TestCase
{
    private function scriptPath(): string
    {
        return \dirname(__DIR__, 3) . '/env/script/install_event_extension.php';
    }

    public function testModuleDeclaresTheInstallScriptForLinux(): void
    {
        $requirements = (string)\file_get_contents(\dirname(__DIR__, 3) . '/env/requirements.php');

        self::assertStringContainsString(
            "'script_linux' => 'script/install_event_extension.php'",
            $requirements,
            'WLS 必须在 env/requirements.php 中声明 Linux 安装脚本',
        );
    }

    public function testScriptBuildsFromSourceWithTheRunningPhpToolchain(): void
    {
        $source = (string)\file_get_contents($this->scriptPath());

        // bundled PHP 无 pecl 时必须能自建：用当前 PHP 的 phpize/php-config 编译。
        self::assertStringContainsString('function tryBuildEventFromSource(', $source);
        self::assertStringContainsString("'phpize'", $source);
        self::assertStringContainsString("'php-config'", $source);
        self::assertStringContainsString('--with-event-core', $source);
        self::assertStringContainsString('--with-event-openssl', $source);
        self::assertStringContainsString('make install', $source);
        self::assertStringContainsString('pecl.php.net/get/event-', $source);

        // 版本解析必须有离线回退，避免一次 REST 失败就整体失败。
        self::assertStringContainsString('EVENT_PECL_FALLBACK_VERSION', $source);
    }

    public function testScriptEnablesExtensionInScanDirWhenNoIniIsLoaded(): void
    {
        $source = (string)\file_get_contents($this->scriptPath());

        // 没有加载 php.ini 时（bundled PHP 常见）必须写入 conf.d，否则新进程不生效。
        self::assertStringContainsString('function enableExtensionInScanDir(', $source);
        self::assertStringContainsString('PHP_CONFIG_FILE_SCAN_DIR', $source);
        self::assertStringContainsString('enableExtensionInScanDir($ext)', $source);
    }

    public function testScriptKeepsCheckAndInstallExitCodeContract(): void
    {
        $source = (string)\file_get_contents($this->scriptPath());

        // 执行器依赖退出码：check 未装返回非 0，install 成功返回 0。
        self::assertStringContainsString("case 'check':", $source);
        self::assertStringContainsString("case 'install':", $source);
        self::assertStringContainsString("exit(\$result['success'] ? 0 : 1);", $source);
    }
}
