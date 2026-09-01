<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendBinQueryPathLocaleContractTest extends TestCase
{
    public function testQueryBinConfigResolvesLocaleFromPathNotCookie(): void
    {
        $source = (string)\file_get_contents(
            \BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString('Path/query language: never read WELINE_USER_LANG cookie', $source);
        self::assertStringContainsString('detectPathLanguage', $source);
        self::assertStringContainsString('readQueryLanguage', $source);
        self::assertStringContainsString('detectPathCurrency', $source);
        self::assertStringContainsString('readQueryCurrency', $source);
        self::assertStringContainsString('resolveCurrentLanguage', $source);
        self::assertStringContainsString('pathname: config.pathname', $source);
        self::assertDoesNotMatchRegularExpression(
            '/buildQueryBinConfig[\s\S]{0,800}?document\.cookie/',
            $source
        );
    }
}
