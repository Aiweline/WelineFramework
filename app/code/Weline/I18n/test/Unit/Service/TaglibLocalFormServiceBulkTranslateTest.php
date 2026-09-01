<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TaglibLocalFormServiceBulkTranslateTest extends TestCase
{
    public function testServiceDeclaresBulkTranslateAndFieldFullyTranslatedGuard(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/TaglibLocalFormService.php',
        );

        self::assertStringContainsString('function buildBulkTranslationSteps', $source);
        self::assertStringContainsString('function aiTranslateFieldsBulk', $source);
        self::assertStringContainsString('function isFieldFullyTranslated', $source);
        self::assertStringContainsString('resolveSiteLocaleCodes', $source);
        self::assertStringContainsString("'skipped_done'", $source);
        self::assertStringContainsString("'skipped_empty'", $source);
        self::assertStringContainsString('所有字段均已翻译或为空，无需补充', $source);
    }

    public function testAdminQueryProviderExposesBulkAiAction(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php',
        );

        self::assertStringContainsString("'taglib-local-ai-bulk'", $source);
        self::assertStringContainsString('aiTranslateTaglibLocalFormBulk', $source);
        self::assertStringContainsString('aiTranslateFieldsBulk', $source);
    }
}
