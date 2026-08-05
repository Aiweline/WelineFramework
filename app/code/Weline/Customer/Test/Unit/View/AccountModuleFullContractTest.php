<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountModuleFullContractTest extends TestCase
{
    public function testAccountModulePublishesTheFullLoaderContract(): void
    {
        $moduleFile = dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline-api-account.js';

        self::assertFileExists($moduleFile);
        $content = (string)file_get_contents($moduleFile);

        self::assertStringContainsString("const AccountModule = {\n        __full: true,", $content);
        self::assertStringContainsString('window.WelineAccountModule = AccountModule;', $content);
    }
}
