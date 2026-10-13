<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Webhook must ACK within platform read timeouts; full release is detached.
 */
final class DeployWebhookAsyncAckContractTest extends TestCase
{
    public function testWebhookReleaseReturnsAcceptedAsyncAndDoesNotCallOrchestratorSync(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DeployWebhookReleaseService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('DeployWebhookAsyncDispatcher', $source);
        self::assertStringContainsString("'accepted' => true", $source);
        self::assertStringContainsString("'async' => true", $source);
        self::assertStringContainsString("'status' => 202", $source);
        self::assertStringNotContainsString('$this->orchestrator->release', $source);
        // Git-versioned deploy: detached CLI always passes --no-backup (third arg true).
        self::assertMatchesRegularExpression(
            '/dispatchRelease\s*\(\s*\(string\)\$refInfo\[\'ref\'\]\s*,\s*\$force\s*,\s*true\s*\)/',
            $source
        );
    }

    public function testAsyncDispatcherSpawnsDetachedDeployRelease(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DeployWebhookAsyncDispatcher.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('deploy:release', $source);
        self::assertStringContainsString('--no-backup', $source);
        self::assertStringContainsString('resolvePhpBinary', $source);
        self::assertStringContainsString('jobQueue', $source);
        self::assertStringContainsString('enqueue', $source);
        self::assertStringContainsString('systemdRunDetach', $source);
        self::assertStringContainsString("'transport' => 'queue'", $source);
        self::assertStringContainsString('DeployReleaseNotifier', $source);
        self::assertStringContainsString('PHASE_QUEUED', $source);
    }
}
