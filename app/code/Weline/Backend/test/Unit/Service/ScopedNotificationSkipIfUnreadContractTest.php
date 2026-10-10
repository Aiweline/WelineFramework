<?php
declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source contract for skip_if_unread (DB-backed cases need live schema).
 */
final class ScopedNotificationSkipIfUnreadContractTest extends TestCase
{
    public function testWriterImplementsSkipIfUnreadBranch(): void
    {
        $writer = \dirname(__DIR__, 3) . '/Service/ScopedNotificationWriter.php';
        $observer = \dirname(__DIR__, 3) . '/Observer/SystemNotificationObserver.php';
        self::assertFileExists($writer);
        self::assertFileExists($observer);
        $writerSrc = (string)\file_get_contents($writer);
        $observerSrc = (string)\file_get_contents($observer);
        self::assertStringContainsString('skip_if_unread', $writerSrc);
        self::assertStringContainsString('hasUnreadRecipients', $writerSrc);
        self::assertStringContainsString("'skipped' => true", $writerSrc);
        self::assertStringContainsString('setDedupeKey(null)', $writerSrc);
        self::assertStringContainsString("!empty(\$result['skipped'])", $observerSrc);
    }
}
