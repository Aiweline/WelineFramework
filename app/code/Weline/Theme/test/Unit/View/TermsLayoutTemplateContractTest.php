<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class TermsLayoutTemplateContractTest extends TestCase
{
    public function testTermsLayoutHasAmazonShellSingleContentSlotAndDefaultCopy(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/terms/default.phtml';
        self::assertFileExists($path);

        $source = (string)file_get_contents($path);
        self::assertStringContainsString('type="header"', $source);
        self::assertStringContainsString('type="footer"', $source);
        self::assertStringContainsString('type="breadcrumb"', $source);
        self::assertStringContainsString('data-testid="storefront-terms-page"', $source);
        self::assertStringContainsString('data-layout="terms"', $source);
        self::assertStringContainsString('amazon-terms__hero', $source);
        self::assertStringContainsString('amazon-terms__content-root-slot', $source);
        self::assertStringContainsString('id="content"', $source);
        self::assertStringContainsString('id="terms-section-1"', $source);
        self::assertStringContainsString('一、服务说明', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::terms::content', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-start', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::head-after', $source);
        self::assertStringContainsString('data-w-area="frontend"', $source);
        self::assertSame(1, substr_count($source, 'id="content"'));
        self::assertStringNotContainsString('id="policy-term-condition-content"', $source);
    }
}
