<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationPublisherMemoryContractTest extends TestCase
{
    public function testPublishRaisesMemoryCeilingBeforeVarExport(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php',
        );

        self::assertStringContainsString('ensurePublishMemoryCeiling', $source);
        self::assertStringContainsString("ini_set('memory_limit', '512M')", $source);
        self::assertStringContainsString('unset($words)', $source);
    }
}
