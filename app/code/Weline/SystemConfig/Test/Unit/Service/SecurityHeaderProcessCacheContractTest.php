<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SecurityHeaderProcessCacheContractTest extends TestCase
{
    public function testOverrideProviderOwnsProcessBag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SystemConfigSecurityHeaderPolicyOverrideProvider.php'
        );
        self::assertStringContainsString('$processOverridesByScope', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
    }

    public function testProcessCacheResetterClearsSecurityOverrideBag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString(
            'SystemConfigSecurityHeaderPolicyOverrideProvider::clearProcessCache()',
            $src,
        );
    }
}
