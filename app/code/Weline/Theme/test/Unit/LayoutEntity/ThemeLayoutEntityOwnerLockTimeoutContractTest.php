<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class ThemeLayoutEntityOwnerLockTimeoutContractTest extends TestCase
{
    public function testOwnerLockWaitIsAtLeastTwoMinutes(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityOwnerLock.php',
        );
        self::assertStringContainsString('120_000_000_000', $src);
        self::assertStringNotContainsString('10_000_000_000', $src);
        self::assertStringContainsString('theme_layout_owner_lock_timeout', $src);
    }
}
