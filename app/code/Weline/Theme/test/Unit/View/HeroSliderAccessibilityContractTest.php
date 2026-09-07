<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeroSliderAccessibilityContractTest extends TestCase
{
    public function testSlideDotLabelInterpolatesItsOneBasedPosition(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/hero-slider/default.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("sprintf((string)__('第%d张'), \$index + 1)", $source);
        self::assertStringNotContainsString("__('第%d张', \$index + 1)", $source);
    }
}
