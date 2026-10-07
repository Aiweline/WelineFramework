<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * is_read 归一化：禁止依赖含糊的 bool 强转；详情读态以 normalizeIsReadFlag 为准。
 */
final class NotificationIsReadFlagContractTest extends TestCase
{
    public function testServiceSourceUsesNormalizeHelperNotBoolCast(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/NotificationService.php'
        );

        self::assertStringContainsString('normalizeIsReadFlag', $source);
        self::assertStringContainsString('private static function normalizeIsReadFlag', $source);
        self::assertStringNotContainsString("'is_read'           => (bool)", $source);
        self::assertStringContainsString("\$raw === '1'", $source);
    }
}
