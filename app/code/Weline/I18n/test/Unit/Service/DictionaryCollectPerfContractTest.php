<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Collect/Export 分层性能契约：单次 compile、area 一次加载、单模块收窄导出、内容相同 no-op。
 */
final class DictionaryCollectPerfContractTest extends TestCase
{
    public function testWebCollectDoesNotDoubleCompileViaConvertToLanguageFile(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php',
        );
        self::assertStringContainsString('$this->compiler->compile()', $source);
        self::assertStringContainsString('pullCompileAfterSummary', $source);
        self::assertStringNotContainsString('->convertToLanguageFile(', $source);
        self::assertStringContainsString('禁止再经', $source);
    }

    public function testPublisherExposesPublishLocalesAndPublicLoadBaseWordAreas(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php',
        );
        self::assertStringContainsString('function publishLocales(', $source);
        self::assertStringContainsString('function loadBaseWordAreas(', $source);
        self::assertStringContainsString('areaByWord ??= $this->loadBaseWordAreas()', $source);
        self::assertStringContainsString("'unchanged' => true", $source);
    }

    public function testResourcePublisherSkipsRevisionBumpWhenUnchanged(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/I18nResourceChangePublisher.php',
        );
        self::assertStringContainsString('lockForUpdate', $source);
        self::assertStringContainsString("(\$payload['unchanged'] ?? false) === true", $source);
    }

    public function testExportLocaleResolverModuleBaselineIsSourcePlusEnUs(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryExportLocaleResolver.php',
        );
        self::assertStringContainsString('BASELINE_EN', $source);
        self::assertStringContainsString('en_US', $source);
        self::assertStringContainsString('resolveForCollect', $source);
        self::assertStringContainsString('republishAll', $source);
    }

    public function testPersistUsesLargerInChunks(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php',
        );
        self::assertStringContainsString('array_chunk($wordKeys, 500)', $source);
        self::assertStringContainsString('array_chunk($existingToMark, 500)', $source);
    }
}
