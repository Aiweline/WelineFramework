<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountLogoutConfirmFallbackContractTest extends TestCase
{
    public function testLogoutConfirmUsesThemeDialogNotNativeConfirm(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/js/account-logout.js';
        self::assertFileExists($file);
        $content = (string)file_get_contents($file);

        self::assertStringContainsString('function getThemeNoticeApi()', $content);
        self::assertStringContainsString('function getThemeUiDialogConfirm()', $content);
        self::assertStringContainsString('Weline.UI.dialog.confirm', $content);
        self::assertStringContainsString('never use window.confirm', $content);
        self::assertStringNotContainsString('window.confirm(', $content);
        self::assertStringNotContainsString(
            'Notice confirmation is unavailable; logout cancelled.',
            $content
        );
    }
}
