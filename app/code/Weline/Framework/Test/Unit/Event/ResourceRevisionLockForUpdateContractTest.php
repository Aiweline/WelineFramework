<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;

final class ResourceRevisionLockForUpdateContractTest extends TestCase
{
    public function testLockForUpdateExistsWithoutBumpingRevisionInSignatureDocs(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Event/ResourceChange/ResourceRevisionService.php',
        );
        self::assertStringContainsString('function lockForUpdate(', $src);
        self::assertStringContainsString('但不推进版本号', $src);
        self::assertStringContainsString('function next(', $src);
        self::assertStringContainsString('assertIdentity', $src);
        self::assertStringContainsString('assertSoleActiveTransaction', $src);
    }
}
