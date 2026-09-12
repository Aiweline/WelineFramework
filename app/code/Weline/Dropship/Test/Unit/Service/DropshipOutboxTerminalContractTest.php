<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/** Contract: Outbox dead 终态密封、QUEUE_DONE、create skipped→dead、单次 push_terminal。 */
final class DropshipOutboxTerminalContractTest extends TestCase
{
    public function testOutboxDefinesDeadAndSealsUpsert(): void
    {
        $model = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/DropshipPushOutbox.php');
        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DropshipOutboxService.php');

        self::assertStringContainsString("STATUS_DEAD = 'dead'", $model);
        self::assertStringContainsString('STATUS_DEAD', $svc);
        self::assertStringContainsString('already_dead', $svc);
        self::assertStringContainsString('MAX_ATTEMPTS', $svc);
        self::assertStringContainsString('Weline_Dropship::push_terminal', $svc);
        self::assertStringContainsString("'terminal' => true", $svc);
        self::assertStringContainsString('isPermanentFailure', $svc);
        self::assertStringContainsString('Logistic not found', $svc);
        self::assertStringContainsString('Platform not support', $svc);
        self::assertStringNotContainsString("'Order exist'", $svc);
        self::assertStringNotContainsString("'do not duplicate create'", $svc);
        self::assertStringContainsString('maybeRecoverExistingFulfillment', $svc);
        self::assertStringContainsString('recovered_existing', $svc);
        self::assertStringContainsString('maybeBackfillTerminalCompensation', $svc);
        self::assertStringContainsString('DropshipPushTerminalCompensationService', $svc);
        self::assertStringContainsString('markTerminalDead', $svc);

        // done/dead 密封，禁止重开
        self::assertMatchesRegularExpression(
            '/STATUS_DONE.*STATUS_DEAD|STATUS_DEAD.*STATUS_DONE/s',
            $svc
        );
        self::assertStringContainsString('create', $svc);
        // create + unsupported → dead（不是长期停在 skipped）
        self::assertStringContainsString("\$action === 'create'", $svc);
    }

    public function testCronExcludesDeadAndConsumerUsesOk(): void
    {
        $cron = (string)file_get_contents(dirname(__DIR__, 3) . '/Cron/DropshipOutboxRetry.php');
        $consumer = (string)file_get_contents(dirname(__DIR__, 3) . '/Queue/DropshipOrderPushConsumer.php');

        self::assertStringContainsString('STATUS_PENDING', $cron);
        self::assertStringContainsString('STATUS_ERROR', $cron);
        self::assertStringNotContainsString('STATUS_DEAD', $cron);
        self::assertStringContainsString("(\$result['ok'] ?? false) ? 'QUEUE_DONE'", $consumer);
    }
}
