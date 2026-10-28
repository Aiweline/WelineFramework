<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Console\I18n\Collect;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\I18n\Cron\DictionaryCollect;
use Weline\I18n\Service\I18nCollectFingerprint;

/**
 * 词典收集增量契约：无变化 → 前置检查 false / execute 返回 skip；有变化 → 只收集并记录变化模块。
 */
final class I18nDictionaryCollectIncrementalTest extends TestCase
{
    private string $root = '';
    private string $stateFile = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-i18n-collect-fp-' . \bin2hex(\random_bytes(6));
        $this->stateFile = $this->root . '/state/i18n_collect.json';
        \mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->root = '';
        $this->stateFile = '';
    }

    public function testNoChangeSkipsPreflightAndExecute(): void
    {
        $moduleDir = $this->makeModule('Acme_Demo');
        $fingerprint = new I18nCollectFingerprint($this->stateFile, ['Acme_Demo' => $moduleDir]);
        $fingerprint->remember(['Acme_Demo']);
        self::assertSame([], $fingerprint->changedModules());

        $compiler = $this->createMock(DictionaryCompiler::class);
        $compiler->expects(self::never())->method('compile');
        $cron = new DictionaryCollect(
            new Collect($compiler, $this->createMock(Printing::class), null),
            $fingerprint,
        );

        self::assertFalse($cron->hasPendingWork());
        self::assertSame('skip:no_changed_modules', $cron->execute());
    }

    public function testChangedModulesOnlyReportsTouchedModuleAndRememberClearsIt(): void
    {
        $alpha = $this->makeModule('Acme_Alpha');
        $beta = $this->makeModule('Acme_Beta');
        $fingerprint = new I18nCollectFingerprint(
            $this->stateFile,
            ['Acme_Alpha' => $alpha, 'Acme_Beta' => $beta],
        );
        self::assertSame(['Acme_Alpha', 'Acme_Beta'], $fingerprint->changedModules());
        $fingerprint->remember(['Acme_Alpha', 'Acme_Beta']);
        self::assertSame([], $fingerprint->changedModules());

        // 只动 Beta 的 i18n 源 CSV（被收集读取的源语言包）
        $csv = $beta . '/i18n/zh_Hans_CN.csv';
        \file_put_contents($csv, "原词,译文,Acme_Beta\n新词,新译,Acme_Beta\n");
        \touch($csv, \time() + 10);

        self::assertSame(['Acme_Beta'], $fingerprint->changedModules());
        $fingerprint->remember(['Acme_Beta']);
        self::assertSame([], $fingerprint->changedModules());
    }

    public function testExecuteCollectsOnlyChangedModuleAndRemembersIt(): void
    {
        $alpha = $this->makeModule('Acme_Alpha');
        $beta = $this->makeModule('Acme_Beta');
        $fingerprint = new I18nCollectFingerprint(
            $this->stateFile,
            ['Acme_Alpha' => $alpha, 'Acme_Beta' => $beta],
        );
        $fingerprint->remember(['Acme_Alpha']);
        self::assertSame(['Acme_Beta'], $fingerprint->changedModules());

        $compiler = $this->createMock(DictionaryCompiler::class);
        $compiler->expects(self::once())
            ->method('compile')
            ->with('Acme_Beta', false, false, self::anything());
        $broadcaster = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $broadcaster->method('cacheClearAndWait')->willReturn(['success' => true, 'completed' => true]);

        $cron = new DictionaryCollect(
            new Collect($compiler, $this->createMock(Printing::class), $broadcaster),
            $fingerprint,
        );

        $result = $cron->execute();

        self::assertStringContainsString('待收集 1 个模块，成功 1 个', $result);
        self::assertSame([], $fingerprint->changedModules());
    }

    private function makeModule(string $name): string
    {
        $dir = $this->root . '/' . $name;
        \mkdir($dir . '/Controller', 0777, true);
        \mkdir($dir . '/view/templates', 0777, true);
        \mkdir($dir . '/i18n', 0777, true);
        \file_put_contents($dir . '/Controller/Index.php', "<?php\n");
        \file_put_contents($dir . '/view/templates/index.phtml', '<lang>视图</lang>');
        \file_put_contents($dir . '/i18n/zh_Hans_CN.csv', "原词,译文,{$name}\n");

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !\is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
                continue;
            }
            @\unlink($file->getPathname());
        }
        @\rmdir($dir);
    }
}
