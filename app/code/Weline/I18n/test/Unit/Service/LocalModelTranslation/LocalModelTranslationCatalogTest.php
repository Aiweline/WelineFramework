<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\LocalModelTranslation;

use PHPUnit\Framework\TestCase;

final class LocalModelTranslationCatalogTest extends TestCase
{
    public function testCatalogSourceDiscoversLocalModelClassesByConvention(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LocalModelTranslation/LocalModelTranslationCatalog.php',
        );

        self::assertStringContainsString('discoverLocalModelClassNames', $source);
        self::assertStringContainsString('is_subclass_of($class, LocalModel::class)', $source);
        self::assertStringContainsString('inferParentModelClass', $source);
        self::assertStringContainsString('resolveTranslatableFields', $source);
        self::assertStringContainsString('schema_fields_', $source);
        self::assertStringContainsString('isTestSourcePath', $source);
        self::assertStringContainsString("['test', 'Test', 'UnitTest', 'tests']", $source);
        self::assertStringContainsString('DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR', $source);
    }

    public function testCatalogIgnoresInheritedGenericLocalModelFields(): void
    {
        $catalog = new \Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog();
        $descriptors = $catalog->descriptors();
        $optionFields = [];
        $promotionFields = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor['local_model'] === 'Weline\\Eav\\Model\\EavAttribute\\Option\\LocalDescription') {
                $optionFields = $descriptor['fields'];
            }
            if ($descriptor['local_model'] === 'Weline\\Promotion\\Model\\PromotionActivityThemeLocal') {
                $promotionFields = $descriptor['fields'];
            }
        }

        self::assertSame(['value'], $optionFields);
        self::assertNotContains('name', $promotionFields);
    }
}
