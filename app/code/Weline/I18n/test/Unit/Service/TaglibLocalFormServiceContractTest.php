<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TaglibLocalFormServiceContractTest extends TestCase
{
    public function testServiceLoadsInstalledLocalesFromLocaleModel(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/TaglibLocalFormService.php',
        );

        self::assertStringContainsString('Locale::schema_fields_IS_ACTIVE', $source);
        self::assertStringContainsString('Locale::schema_fields_IS_INSTALL', $source);
        self::assertStringContainsString('resolveSiteLocaleCodes', $source);
        self::assertStringContainsString("w_query('websites', 'getWebsiteLanguageCodes'", $source);
        self::assertStringContainsString('resolveCurrentWebsiteId', $source);
        self::assertStringNotContainsString('getActiveLocalsModel', $source);
        self::assertStringContainsString('getLocaleLanguageSelfName', $source);
        self::assertStringContainsString('function aiTranslate', $source);
        self::assertStringContainsString('I18nAiTranslationAdapter', $source);
        self::assertStringContainsString('Weline\\I18n\\Api\\Localization\\LocalModel', $source);
        self::assertStringNotContainsString('use Weline\\I18n\\LocalModel;', $source);
        self::assertStringContainsString('当前站点未配置可编辑的关联语言', $source);
    }

    public function testAdminQueryProviderExposesTaglibLocalLoad(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php',
        );

        self::assertStringContainsString("'taglib-local-load'", $source);
        self::assertStringContainsString("'taglib-local-ai'", $source);
        self::assertStringContainsString('aiTranslateTaglibLocalForm', $source);
        self::assertStringContainsString('TaglibLocalFormService', $source);
    }

    public function testWelineUiExposesWhenReadyForLazyComponents(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/weline-ui.js',
        );

        self::assertStringContainsString('function whenReady', $source);
        self::assertStringContainsString('instances.set(element, map);', $source);
        self::assertStringContainsString('weline:ui:component:ready', $source);
        self::assertStringContainsString('whenReady,', $source);
    }
}
