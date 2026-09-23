<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Guest account.current must be success:true so BinQuery / DevConsole do not ERR.
 */
final class AccountQueryProviderGuestCurrentContractTest extends TestCase
{
    public function testGuestCurrentReturnsSuccessShapeLikeMenuSignals(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("return \$this->success('Not signed in.', [", $source);
        self::assertStringContainsString("'isLogin' => false,", $source);
        self::assertStringContainsString("'logged_in' => false,", $source);
        self::assertStringContainsString("'user' => null,", $source);
        self::assertDoesNotMatchRegularExpression(
            "/'success'\\s*=>\\s*false[\\s\\S]{0,160}'message'\\s*=>\\s*'Not signed in\\.'/",
            $source
        );
    }
}
