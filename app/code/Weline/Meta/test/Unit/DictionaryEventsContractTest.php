<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryEventsContractTest extends TestCase
{
    public function testMetaWritePathsDoNotRegisterDictionaryMeta(): void
    {
        $metaModel = (string)file_get_contents(dirname(__DIR__, 2) . '/Model/Meta.php');
        $templateCompile = (string)file_get_contents(dirname(__DIR__, 2) . '/Observer/TemplateCompile.php');
        $scan = (string)file_get_contents(dirname(__DIR__, 2) . '/Console/Meta/ScanConvention.php');
        $metaData = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/MetaData.php');
        $cron = (string)file_get_contents(dirname(__DIR__, 2) . '/Cron/MetaTranslation.php');

        self::assertStringNotContainsString('DictionaryEvents::register', $metaModel);
        self::assertStringContainsString('MetaTranslation::setTranslatedValue', $metaModel);

        self::assertStringNotContainsString('DictionaryEvents::register', $templateCompile);
        self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $templateCompile);

        self::assertStringNotContainsString('DictionaryEvents::register', $scan);
        self::assertStringContainsString('MetaTranslation::setTranslatedValue', $scan);

        self::assertStringNotContainsString('dictionaryRepository()->upsert($translationKey', $metaData);
        self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $metaData);

        self::assertStringNotContainsString("word_prefix' => '@meta::'", $cron);
        self::assertStringContainsString('MetaLocalTranslationService', $cron);
        self::assertStringNotContainsString('DictionaryEvents::translate', $cron);
    }

    public function testAppearanceTokenGroupWritesMetaLocal(): void
    {
        $helper = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Theme/Helper/AppearanceTokenGroupMeta.php'
        );
        self::assertStringNotContainsString('DictionaryEvents::register', $helper);
        self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $helper);
    }
}
