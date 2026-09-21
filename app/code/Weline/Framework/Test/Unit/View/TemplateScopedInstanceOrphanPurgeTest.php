<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * When RequestContext is already gone, fiber reset must purge orphaned req:* Template buckets.
 */
final class TemplateScopedInstanceOrphanPurgeTest extends TestCase
{
    public function testResetInstancePurgesReqScopedOrphansWithoutRequestContext(): void
    {
        $path = \dirname(__DIR__, 3) . '/View/Template.php';
        $src = \file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString('purgeOrphanedRequestScopedInstances', $src);
        $resetPos = \strpos($src, 'public static function resetInstance(): void');
        self::assertNotFalse($resetPos);
        $purgePos = \strpos($src, 'private static function purgeOrphanedRequestScopedInstances(): void');
        self::assertNotFalse($purgePos);
        $resetBody = \substr($src, $resetPos, $purgePos - $resetPos);
        self::assertStringContainsString('self::purgeOrphanedRequestScopedInstances()', $resetBody);
        self::assertStringContainsString("str_starts_with(\$key, 'req:')", $src);
    }
}
