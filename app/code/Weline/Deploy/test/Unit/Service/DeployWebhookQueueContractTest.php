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
        self::assertStringContainsString("'status' => 'pending'", $queue);
        self::assertStringContainsString('function findByRef', $queue);
        self::assertStringContainsString('function listByStatus', $queue);
    }
}
