<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

/**
 * QA-12: guest account redirects must pass referer as a params bag so
 * Url::extractedUrl / http_build_query encodes once — never urlencode() into the path.
 */
final class AccountIndexLoginRefererContractTest extends TestCase
{
    public function testGuestRedirectPassesRefererViaParamsNotPreEncodedQuery(): void
    {
        $controllerPath = \dirname(__DIR__, 4) . '/Controller/Account/Index.php';
        self::assertFileExists($controllerPath);
        $source = (string)\file_get_contents($controllerPath);

        self::assertStringContainsString(
            "\$this->redirect('/customer/account/login', ['referer' => \$currentUrl])",
            $source
        );
        self::assertStringNotContainsString(
            "login?referer=' . urlencode(",
            $source
        );
        self::assertStringNotContainsString(
            'login?referer=" . urlencode(',
            $source
        );
    }
}
