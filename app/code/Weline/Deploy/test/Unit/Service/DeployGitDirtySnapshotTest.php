<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployGitMetadataService;

final class DeployGitDirtySnapshotTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
        $this->root = '';
    }

    public function testSnapshotsModifiedAndUntrackedFiles(): void
    {
        $this->initRepository();
        file_put_contents($this->root . '/tracked.txt', "changed\n");
        mkdir($this->root . '/untracked/nested', 0775, true);
        file_put_contents($this->root . '/untracked/nested/new.txt', "new\n");

        $service = new DeployGitMetadataService();
        $snapshot = $service->snapshotDirtyWorktree('unit_test', $this->root);

        self::assertNotSame('', $snapshot);
        self::assertDirectoryExists($snapshot);
        self::assertFileExists($snapshot . '/status.txt');
        self::assertFileExists($snapshot . '/tracked.patch');
        self::assertFileExists($snapshot . '/untracked/untracked/nested/new.txt');
        self::assertStringContainsString('+changed', (string)file_get_contents($snapshot . '/tracked.patch'));

        $manifest = json_decode((string)file_get_contents($snapshot . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(2, $manifest['dirty_entries'] ?? null);
        self::assertSame(1, $manifest['untracked_copied'] ?? null);
    }

    public function testPrunesOldSnapshots(): void
    {
        $this->initRepository();
        file_put_contents($this->root . '/tracked.txt', "changed\n");

        $service = new DeployGitMetadataService();
        for ($i = 0; $i < 3; $i++) {
            self::assertNotSame('', $service->snapshotDirtyWorktree('unit_test', $this->root, 2));
        }

        $directories = glob($this->root . '/var/deploy/dirty-snapshots/*', GLOB_ONLYDIR) ?: [];
        self::assertCount(2, $directories);
    }

    public function testCleanWorktreeReturnsEmptySnapshotPath(): void
    {
        $this->initRepository();

        $service = new DeployGitMetadataService();
        self::assertSame('', $service->snapshotDirtyWorktree('unit_test', $this->root));
    }

    private function initRepository(): void
    {
        $this->root = BP . '/dev/tmp/deploy-dirty-snapshot-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0775, true));
        $this->git('init -q');
        $this->git('config user.email test@example.com');
        $this->git('config user.name Weline Test');
        file_put_contents($this->root . '/.gitignore', "var/\n");
        file_put_contents($this->root . '/tracked.txt', "base\n");
        $this->git('add .gitignore tracked.txt');
        $this->git('commit -q -m base');
    }

    private function git(string $command): void
    {
        $output = [];
        $exitCode = 1;
        exec('cd ' . escapeshellarg($this->root) . ' && git ' . $command . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, 'git ' . $command . ' failed: ' . implode("\n", $output));
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target) && !is_link($target)) {
                $this->removeDirectory($target);
                continue;
            }
            @unlink($target);
        }
        @rmdir($path);
    }
}
