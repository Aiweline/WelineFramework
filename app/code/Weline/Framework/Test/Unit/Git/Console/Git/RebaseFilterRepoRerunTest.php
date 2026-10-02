<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Git\Console\Git;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Git\Console\Git\Rebase;

/**
 * 回归：`git:rebase remove` 必须能处理 filter-repo 的「Already Ran」交互闸门。
 *
 * 该命令经 runProc() 启动子进程并立即关闭其 stdin，无法内联回答
 * “Treat this run as a continuation of filtering in the previous run? (Y/N)”。
 * 因此真正执行前必须把上次元数据归档（.git/filter-repo →
 * .git/filter-repo-previous-<YmdHis>），使 filter-repo 以「新的一次过滤」非交互继续。
 */
final class RebaseFilterRepoRerunTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'weline-git-rebase-'
            . \bin2hex(\random_bytes(4));
        \mkdir($this->root . \DIRECTORY_SEPARATOR . '.git' . \DIRECTORY_SEPARATOR . 'filter-repo', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testArchivesPreviousRunSoFilterRepoNeedsNoInteractiveAnswer(): void
    {
        $stateDir = $this->root . '/.git/filter-repo';
        \file_put_contents($stateDir . '/already_ran', 'existing marker');
        \file_put_contents($stateDir . '/commit-map', "old new\n");

        $handled = $this->invokeArchive($this->root);

        self::assertIsString($handled, '存在 already_ran 时必须处理，否则第二次重写会 EOFError');
        self::assertStringContainsString('filter-repo-previous-', $handled);
        self::assertDirectoryExists($handled);
        self::assertFileDoesNotExist(
            $stateDir . '/already_ran',
            '闸门标记必须离开 .git/filter-repo，filter-repo 才不会再交互提问',
        );
        self::assertFileExists($handled . '/already_ran', '归档需保留原标记以备回溯');
        self::assertFileExists($handled . '/commit-map', '原 commit-map 必须随归档保留以供审计');
    }

    public function testLeavesStateUntouchedWhenThereIsNoPreviousRun(): void
    {
        $stateDir = $this->root . '/.git/filter-repo';
        \file_put_contents($stateDir . '/commit-map', "old new\n");

        self::assertNull($this->invokeArchive($this->root), '首次过滤不应产生任何副作用');
        self::assertDirectoryExists($stateDir);
        self::assertFileExists($stateDir . '/commit-map');
    }

    private function invokeArchive(string $cwd): ?string
    {
        $command = (new \ReflectionClass(Rebase::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Rebase::class, 'archivePreviousFilterRepoRun');

        return $method->invoke($command, $cwd);
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\is_dir($path)) {
            return;
        }
        $items = \scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($child) && !\is_link($child)) {
                $this->removeTree($child);
                continue;
            }
            @\unlink($child);
        }
        @\rmdir($path);
    }
}
