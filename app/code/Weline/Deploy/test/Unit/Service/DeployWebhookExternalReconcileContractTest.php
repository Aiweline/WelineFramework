<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployWebhookExternalJobReconciler;

/**
 * External systemd-run jobs must close out without re-running release.
 */
final class DeployWebhookExternalReconcileContractTest extends TestCase
{
    public function testReconcilerAndWiringExist(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Service/DeployWebhookExternalJobReconciler.php');

        $queue = (string)file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
        self::assertStringContainsString('function listByStatus', $queue);

        $async = (string)file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
        self::assertStringContainsString("--job-id=", $async);
        // job-id must be appended after enqueue (job_id known).
        self::assertMatchesRegularExpression(
            '/\$jobId\s*=\s*\(string\)\$queued\[\'job_id\'\];\s*.*?\$argv\[\]\s*=\s*\'--job-id=\'\s*\.\s*\$jobId/s',
            $async
        );

        $release = (string)file_get_contents($base . '/Console/Deploy/Release.php');
        self::assertStringContainsString('DeployWebhookJobQueue', $release);
        self::assertStringContainsString('closeoutJob', $release);
        self::assertStringContainsString('printResultTrailer', $release);
        self::assertStringContainsString('RESULT_TRAILER_PREFIX', $release);
        $reconcilerSrc = (string)file_get_contents($base . '/Service/DeployWebhookExternalJobReconciler.php');
        self::assertStringContainsString("RESULT_TRAILER_PREFIX = '# deploy-job-result '", $reconcilerSrc);
        // updateJob before exit(1)
        $failPos = strpos($release, "exit(1)");
        $closePos = strpos($release, 'closeoutJob');
        self::assertNotFalse($failPos);
        self::assertNotFalse($closePos);
        self::assertLessThan($failPos, $closePos);

        $drain = (string)file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
        self::assertStringContainsString('DeployWebhookExternalJobReconciler', $drain);
        self::assertStringContainsString('externalReconciler->reconcile', $drain);
        self::assertStringContainsString("'reconciled'", $drain);
    }

    public function testReadEvidenceParsesTrailerAndLegacySuccess(): void
    {
        $reconciler = new DeployWebhookExternalJobReconciler(
            new class extends \Weline\Deploy\Service\DeployWebhookJobQueue {
            }
        );

        $dir = sys_get_temp_dir() . '/weline-deploy-reconcile-' . bin2hex(random_bytes(4));
        self::assertTrue(@mkdir($dir, 0755, true) || is_dir($dir));

        $trailer = $dir . '/trailer.log';
        file_put_contents(
            $trailer,
            "noise\n" . DeployWebhookExternalJobReconciler::RESULT_TRAILER_PREFIX
            . '{"success":true,"job_id":"j1","release_id":"r1","deploy_version":"abc123","message":""}' . "\n"
        );
        $ev = $reconciler->readEvidence($trailer);
        self::assertSame('success', $ev['kind']);
        self::assertSame('abc123', $ev['deploy_version']);
        self::assertSame('r1', $ev['release_id']);

        $legacy = $dir . '/legacy.log';
        file_put_contents($legacy, "版本戳已写入：92e240e7b\n发布成功：92e240e7b\n");
        $ev2 = $reconciler->readEvidence($legacy);
        self::assertSame('success', $ev2['kind']);
        self::assertSame('92e240e7b', $ev2['deploy_version']);

        $failLog = $dir . '/fail.log';
        file_put_contents(
            $failLog,
            DeployWebhookExternalJobReconciler::RESULT_TRAILER_PREFIX
            . '{"success":false,"job_id":"j2","release_id":"","deploy_version":"","message":"boom"}' . "\n"
        );
        $ev3 = $reconciler->readEvidence($failLog);
        self::assertSame('failed', $ev3['kind']);
        self::assertSame('boom', $ev3['message']);

        @unlink($trailer);
        @unlink($legacy);
        @unlink($failLog);
        @rmdir($dir);
    }
}
