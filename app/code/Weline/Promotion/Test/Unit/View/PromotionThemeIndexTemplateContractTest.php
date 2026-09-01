<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class PromotionThemeIndexTemplateContractTest extends TestCase
{
    public function testIndexTemplateHasScopeFilterControls(): void
    {
        $path = __DIR__ . '/../../../view/templates/backend/promotion/theme/index.phtml';
        $content = (string)file_get_contents($path);

        self::assertStringContainsString('data-testid="promotion-theme-scope-filter"', $content);
        self::assertStringContainsString('w:websites:website:select', $content);
        self::assertStringContainsString('w:websites:store:select', $content);
        self::assertStringContainsString('w:websites:channel:select', $content);
        self::assertStringContainsString('scope_label', $content);
        self::assertStringContainsString('promotionThemeIndexReloadScope', $content);
    }

    public function testControllerIndexAssignsScopeFilterData(): void
    {
        $path = __DIR__ . '/../../../Controller/Backend/Theme.php';
        $content = (string)file_get_contents($path);

        self::assertStringContainsString("assign('filter_website_id'", $content);
        self::assertStringContainsString("assign('scope_filter_active'", $content);
        self::assertStringContainsString('describeScope', $content);
        self::assertStringContainsString('listForBackend($filters)', $content);
    }
}
