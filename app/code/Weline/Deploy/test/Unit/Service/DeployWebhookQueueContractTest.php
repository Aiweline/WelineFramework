<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DeployWebhookQueueContractTest extends TestCase
{
    public function testQueueAndDrainAndCronExist(): void
    {
        $base = dirname(__DIR__, 3);
        self::assertFileExists($base . '/Service/DeployWebhookJobQueue.php');
        self::assertFileExists($base . '/Service/DeployWebhookJobDrainService.php');
        self::assertFileExists($base . '/Cron/DeployWebhookDrainCron.php');
        self::assertFileExists($base . '/Cron/DeployWebhookExpectCron.php');
        self::assertFileExists($base . '/Console/Deploy/Webhook/Drain.php');

        $cron = (string)file_get_contents($base . '/Cron/DeployWebhookDrainCron.php');
        self::assertStringContainsString("'* * * * *'", $cron);
        self::assertStringContainsString('DeployWebhookJobDrainService', $cron);
        self::assertStringContainsString('reconciled=', $cron);

        $queue = (string)file_get_contents($base . '/Service/DeployWebhookJobQueue.php');
        self::assertStringContainsString('webhook-queue', $queue);
        self::assertStringContainsString("'status' => self::STATUS_PENDING", $queue);
        self::assertStringContainsString('function findByRef', $queue);
        self::assertStringContainsString('function listByStatus', $queue);
        self::assertStringContainsString('function hasInFlight', $queue);
        self::assertStringContainsString('function abandonIncomplete', $queue);
        self::assertStringContainsString('function abandonStaleIncomplete', $queue);
        self::assertStringContainsString('STATUS_ABANDONED', $queue);
        self::assertStringContainsString('STALE_INCOMPLETE_SECONDS = 1800', $queue);

        $dispatcher = (string)file_get_contents($base . '/Service/DeployWebhookAsyncDispatcher.php');
        self::assertStringContainsString('abandonIncomplete(', $dispatcher);
        self::assertStringContainsString('abandonStaleIncomplete(', $dispatcher);
        self::assertStringContainsString('superseded_by_newer_webhook', $dispatcher);
        self::assertStringContainsString('PHASE_ABANDONED', $dispatcher);

        $drain = (string)file_get_contents($base . '/Service/DeployWebhookJobDrainService.php');
        self::assertStringContainsString('abandonStaleIncomplete(', $drain);
        self::assertStringContainsString('PHASE_ABANDONED', $drain);
    }
}
