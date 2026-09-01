<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AboutLayoutTemplateContractTest extends TestCase
{
    public function testAboutLayoutHasAmazonShellSlotsAndHooks(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/about/default.phtml';
        self::assertFileExists($path);

        $source = (string)file_get_contents($path);
        self::assertStringContainsString('type="header"', $source);
        self::assertStringContainsString('type="footer"', $source);
        self::assertStringContainsString('type="breadcrumb"', $source);
        self::assertStringContainsString('data-testid="storefront-about-page"', $source);
        self::assertStringContainsString('data-layout="about"', $source);
        self::assertStringContainsString('amazon-about__hero', $source);
        self::assertStringContainsString('amazon-about__shop-link', $source);
        self::assertStringContainsString('id="about-hero"', $source);
        self::assertStringContainsString('id="about-values"', $source);
        self::assertStringContainsString('id="about-timeline"', $source);
        self::assertStringContainsString('layout-about-content', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::about::hero', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::about::values', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-start', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::head-after', $source);
        self::assertStringContainsString('data-w-area="frontend"', $source);
        self::assertStringContainsString('var(--color-primary', $source);
        self::assertStringContainsString('var(--color-bg-dark', $source);
    }
}
