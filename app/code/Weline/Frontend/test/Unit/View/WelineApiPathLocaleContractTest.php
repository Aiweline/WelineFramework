<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiPathLocaleContractTest extends TestCase
{
    public function testQueryBinClientResolvesLocaleFromPathNotCookie(): void
    {
        $api = (string)\file_get_contents(
            \BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js'
        );
        $worker = (string)\file_get_contents(
            \BP . 'app/code/Weline/Frontend/view/statics/js/weline-api-worker.js'
        );

        self::assertStringContainsString('Path/query language: never read WELINE_USER_LANG cookie', $api);
        self::assertStringContainsString('detectPathLanguage', $api);
        self::assertStringContainsString('readQueryLanguage', $api);
        self::assertStringContainsString('readDocumentLanguage', $api);
        self::assertStringContainsString('resolveCurrentLanguage', $api);
        self::assertStringContainsString('detectPathCurrency', $api);
        self::assertStringContainsString('readQueryCurrency', $api);
        self::assertStringContainsString('pathname:', $api);
        self::assertStringContainsString('detectPathLanguage(config.pathname', $worker);
        self::assertStringNotContainsString("document.cookie.match('WELINE_USER_LANG", $api);
    }
}
