<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class IdempotentQueueAdmissionContractTest extends TestCase
{
    public function testAdmissionUsesCreateIfAbsentWithFollowupAndReopen(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/IdempotentQueueAdmission.php',
        );

        self::assertStringContainsString("createIfAbsent", $source);
        self::assertStringContainsString("':followup'", $source);
        self::assertStringContainsString('requeueQueueSafely', $source);
        self::assertStringContainsString('dispatchQueueIfEligible', $source);
        self::assertStringContainsString('queue_edit_active', $source);
    }
}
