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
    }
}
