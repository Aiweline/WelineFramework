<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeadCurrencyContextContractTest extends TestCase
{
    public function testDefaultHeadUsesFrameworkCurrencyStateAsItsSingleSourceOfTruth(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/head/default.phtml';

        self::assertFileExists($path);
        $template = (string)file_get_contents($path);

        self::assertStringContainsString(
            '$currentCurrency = \\Weline\\Framework\\App\\State::getCurrency();',
            $template
        );
        self::assertStringContainsString("\\Weline\\Framework\\App\\Env::system('currency', 'CNY')", $template);
        self::assertStringNotContainsString("\\w_env('user.currency', 'CNY')", $template);
        self::assertStringNotContainsString("\\Weline\\Framework\\App\\Env::get('currency', 'CNY')", $template);
    }
}
