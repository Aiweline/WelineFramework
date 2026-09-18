<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;

final class StorefrontNotFoundStaticGeneratorStripDictionariesContractTest extends TestCase
{
    public function testStripEmptiesWidgetTranslationsObject(): void
    {
        $html = <<<'HTML'
<script>
var customerServiceConfig = {
    storefrontLocale: "el_GR",
    widgetTranslations: {"zh_Hans_CN":{"客服服务":"客服服务"},"en_US":{"客服服务":"Customer Service"},"el_GR":{"客服服务":"Customer Service"}},
    other: true
};
</script>
HTML;

        $stripped = StorefrontNotFoundStaticGenerator::stripClientTranslationDictionaries($html);

        self::assertStringContainsString('widgetTranslations: {}', $stripped);
        self::assertStringNotContainsString('"zh_Hans_CN"', $stripped);
        self::assertStringNotContainsString('"en_US"', $stripped);
        self::assertStringContainsString('storefrontLocale: "el_GR"', $stripped);
        self::assertStringContainsString('other: true', $stripped);
    }

    public function testStripHandlesEscapedQuotesInsideDictionary(): void
    {
        $html = 'widgetTranslations: {"fr_FR":{"显示模式":"Mode d\u0027affichage","x":"a\\"b"}}, done';
        $stripped = StorefrontNotFoundStaticGenerator::stripClientTranslationDictionaries($html);
        self::assertSame('widgetTranslations: {}, done', $stripped);
    }

    public function testStripIsNoopWithoutWidgetTranslations(): void
    {
        $html = '<html><body>404</body></html>';
        self::assertSame($html, StorefrontNotFoundStaticGenerator::stripClientTranslationDictionaries($html));
    }

    public function testGeneratorAppliesStripDuringBuild(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontNotFoundStaticGenerator.php'
        );
        self::assertStringContainsString('stripClientTranslationDictionaries', $source);
        self::assertStringContainsString('StaticErrorPagePublisher::CTX_PUBLISHING', $source);
        self::assertStringContainsString('404v6', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Http/StaticErrorPagePublishFingerprint.php'
        ));
    }
}
