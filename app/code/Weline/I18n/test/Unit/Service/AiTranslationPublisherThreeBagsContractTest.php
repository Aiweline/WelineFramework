<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationPublisherThreeBagsContractTest extends TestCase
{
    public function testPublisherWritesExclusiveAreaBags(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php'
        );
        self::assertStringContainsString('localeBagBasename', $src);
        self::assertStringContainsString('AREA_SHARED', $src);
        self::assertStringContainsString('AREA_FRONTEND', $src);
        self::assertStringContainsString('AREA_BACKEND', $src);
        self::assertStringContainsString('loadBaseWordAreas', $src);
        self::assertStringContainsString('三袋互斥', $src);
    }
}
