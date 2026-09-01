<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryEventsContractTest extends TestCase
{
    public function testMetaUsesFrameworkDictionaryEventsForRegistration(): void
    {
        $metaModel = (string)file_get_contents(dirname(__DIR__, 2) . '/Model/Meta.php');
        $templateCompile = (string)file_get_contents(dirname(__DIR__, 2) . '/Observer/TemplateCompile.php');
        $scan = (string)file_get_contents(dirname(__DIR__, 2) . '/Console/Meta/ScanConvention.php');

        self::assertStringContainsString('DictionaryEvents::register', $metaModel);
        self::assertStringNotContainsString('Weline_I18n::collect_translations', $metaModel);
        self::assertStringContainsString('DictionaryEvents::register', $templateCompile);
        self::assertStringNotContainsString('Weline_I18n::collect_translations', $templateCompile);
        self::assertStringContainsString('DictionaryEvents::register', $scan);
    }

    public function testMetaCronUsesDictionaryTranslateForMetaPrefix(): void
    {
        $cron = (string)file_get_contents(dirname(__DIR__, 2) . '/Cron/MetaTranslation.php');
        self::assertStringContainsString("word_prefix' => '@meta::'", $cron);
        self::assertStringContainsString('MetaLocalTranslationService', $cron);
    }
}
