<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Protect roots must snapshot/restore only git-tracked files (ls-files), never whole-tree removePath.
 */
final class DeployProtectedTrackedFilesContractTest extends TestCase
{
    public function testSnapshotUsesLsFilesAndCopiesTrackedFilesOnly(): void
    {
        $source = $this->orchestratorSource();

        self::assertStringContainsString('listTrackedFilesUnder', $source);
        self::assertStringContainsString("['git', 'ls-files', '-z', '--', \$relativePath]", $source);

        if (preg_match(
            '/function snapshotProtectedPaths\(string \$deployRoot\): string\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            $m
        ) !== 1) {
            self::fail('snapshotProtectedPaths not found');
        }
        $body = $m['body'];
        self::assertStringContainsString('listTrackedFilesUnder', $body);
        self::assertStringContainsString('copyPath($source, $target)', $body);
        // Must not whole-tree copy the protect root directory without ls-files.
        self::assertStringNotContainsString(
            "\$source = \$deployRoot . DS . str_replace('/', DS, \$relativePath);\n            if (!file_exists(\$source)) {\n                continue;\n            }\n            \$target = \$snapshotRoot . str_replace('/', DS, \$relativePath);\n            \$this->copyPath(\$source, \$target);",
            $body
        );
    }

    public function testRestoreNeverRemovePathProtectRootsAndUnlinksTrackedExtras(): void
    {
        $source = $this->orchestratorSource();

        if (preg_match(
            '/function restoreProtectedPaths\(string \$deployRoot, string \$snapshotId\): void\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            $m
        ) !== 1) {
            self::fail('restoreProtectedPaths not found');
        }
        $body = $m['body'];

        self::assertStringContainsString('listSnapshotFilesUnder', $body);
        self::assertStringContainsString('listTrackedFilesUnder', $body);
        self::assertStringContainsString('@unlink($path)', $body);
        self::assertStringContainsString('$this->removePath($snapshotRoot)', $body);
        // Forbidden: wipe protect root tree before copy.
        self::assertStringNotContainsString('removePath($target)', $body);
        self::assertStringNotContainsString('if (is_dir($source))', $body);
    }

    public function testListTrackedFilesFailsClosedOnLsFilesError(): void
    {
        $source = $this->orchestratorSource();

        if (preg_match(
            '/function listTrackedFilesUnder\(string \$deployRoot, string \$relativePath\): array\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            $m
        ) !== 1) {
            self::fail('listTrackedFilesUnder not found');
        }
        $body = $m['body'];

        self::assertStringContainsString('ls-files', $body);
        self::assertStringContainsString('$exitCode !== 0', $body);
        self::assertStringContainsString('RuntimeException', $body);
        // No silent whole-tree fallback.
        self::assertStringNotContainsString('RecursiveDirectoryIterator', $body);
        self::assertStringNotContainsString('copyPath', $body);
    }

    private function orchestratorSource(): string
    {
        $path = dirname(__DIR__, 3) . '/Service/DeployOrchestratorService.php';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
