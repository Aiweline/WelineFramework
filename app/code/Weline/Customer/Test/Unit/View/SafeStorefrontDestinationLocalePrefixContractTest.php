<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Login/recovery destination helpers must not double-prefix /{locale}?w_auth=1.
 */
final class SafeStorefrontDestinationLocalePrefixContractTest extends TestCase
{
    public function testAccountLoginJsTreatsLocaleQueryAsAlreadyPrefixed(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/account-login.js';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('destination.startsWith(`${prefix}?`)', $src);
        self::assertStringContainsString('destination.startsWith(`${prefix}#`)', $src);
        self::assertStringContainsString('never emit /bg_BG/bg_BG', $src);
    }

    public function testAccountRecoveryJsTreatsLocaleQueryAsAlreadyPrefixed(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/account-recovery.js';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('destination.startsWith(`${prefix}?`)', $src);
        self::assertStringContainsString('destination.startsWith(`${prefix}#`)', $src);
        self::assertStringContainsString('never emit /bg_BG/bg_BG', $src);
    }
}
