<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Deploy release/rollback is git-versioned: prior commit/tag is the restore point.
 */
final class DeployGitVersionNoForcedBackupContractTest extends TestCase
{
    public function testOrchestratorDoesNotForceProdSiteTar(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DeployOrchestratorService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('return !$noBackup;', $source);
        self::assertStringContainsString("no_backup'] ?? true", $source);
        // Must not reintroduce "prod always backup" gate inside shouldRunBackup.
        if (preg_match('/function shouldRunBackup\(bool \$noBackup\): bool\s*\{(?P<body>.*?)\n    \}/s', $source, $m) === 1) {
            self::assertStringNotContainsString('isProductionDeploy()', $m['body']);
            self::assertStringContainsString('return !$noBackup;', $m['body']);
        } else {
            self::fail('shouldRunBackup not found');
        }
    }

    public function testCliReleaseDefaultsToNoBackupUnlessBackupFlag(): void
    {
        $path = dirname(__DIR__, 3) . '/Console/Deploy/Release.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("\$noBackup    = !isset(\$args['backup']);", $source);
        self::assertStringContainsString("'--backup'", $source);
    }

    public function testSiteBackupExcludesVarBackupDirectory(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DeploySiteBackupService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("exclude='var/backup'", $source);
    }
}
