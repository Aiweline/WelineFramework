<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\View;

use PHPUnit\Framework\TestCase;

final class NewArrivalsEmptyShellContractTest extends TestCase
{
    public function testNewArrivalsEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product/new-arrivals/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('if ($products === []) {', $source);
        self::assertStringContainsString('data-testid="new-arrivals-empty"', $source);
        self::assertStringContainsString('hidden', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);
    }

    public function testHomepageHidesSectionWhenNewArrivalsEmpty(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('homepage-new-arrivals', $source);
        self::assertStringContainsString(
            '.homepage-section:has([data-testid="new-arrivals-empty"])',
            $source,
        );
        self::assertStringContainsString('display: none', $source);
    }
}
