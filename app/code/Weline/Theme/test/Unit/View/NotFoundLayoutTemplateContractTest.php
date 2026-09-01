<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class NotFoundLayoutTemplateContractTest extends TestCase
{
    public function testNotFoundLayoutHasHeaderFooterAndRecommendationSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/not_found/default.phtml';
        self::assertFileExists($path);

        $source = (string)file_get_contents($path);
        self::assertStringContainsString('type="header"', $source);
        self::assertStringContainsString('type="footer"', $source);
        self::assertStringContainsString('id="not-found-recommendations"', $source);
        self::assertStringContainsString('data-testid="storefront-not-found-page"', $source);
        self::assertStringContainsString('amazon-not-found__intro', $source);
        self::assertStringContainsString('amazon-not-found__shop-panel', $source);
        self::assertStringContainsString('amazon-not-found__home-link', $source);
        self::assertStringContainsString('layout-not-found-recommendations', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::not-found::recommendations', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-start', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::head-after', $source);
        self::assertStringContainsString('data-w-area="frontend"', $source);
    }
}
