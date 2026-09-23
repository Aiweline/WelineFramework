<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * QA-13: Magento-style /customer/account/create → 301 register.
 */
final class RegisterAliasRedirectContractTest extends TestCase
{
    public function testRouterDeclaresCreateAliasPermanentRedirect(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Router.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString("'customer/account/create'", $source);
        self::assertStringContainsString('ResponseTerminateException(301', $source);
        self::assertStringContainsString('customer/account/register', $source);
    }
}
