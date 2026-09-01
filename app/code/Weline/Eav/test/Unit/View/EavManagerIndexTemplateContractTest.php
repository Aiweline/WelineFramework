<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerIndexTemplateContractTest extends TestCase
{
    public function testManagerConfigUsesResolvedBackendUrlNotTaglibLiteral(): void
    {
        $index = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/index.phtml',
        );
        $surface = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );

        self::assertStringContainsString('Weline_Eav::templates/Backend/Manager/surface.phtml', $index);
        self::assertStringContainsString("'apiBase' => \$this->getUrl('eav/backend/manager')", $surface);
        self::assertStringNotContainsString("@backend-url(\"eav/backend/manager\")", $surface);
    }
}
