<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Rules\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Rules\Frontend\WidgetLayoutStabilityScanner;

final class WidgetLayoutStabilityScannerTest extends TestCase
{
    public function testHydrateWithoutSkeletonFails(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-skel-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<section data-pdp-lazy-shell="1" data-weline-hydrate="1" class="is-deferred"><div></div></section>'
        );
        $rel = 'Weline/Demo/view/templates/frontend/widgets/demo.phtml';
        $issues = $scanner->scanFile($tmp, $rel);
        @unlink($tmp);
        self::assertNotSame([], $issues);
        self::assertSame(WidgetLayoutStabilityScanner::TYPE_MISSING_SKELETON, $issues[0]['type']);
    }

    public function testHydrateWithCardSkeletonPasses(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-skel-ok-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<section data-pdp-lazy-shell="1" class="is-deferred">'
            . '<div class="w-skeleton" data-size="card"></div></section>'
        );
        $issues = $scanner->scanFile($tmp, 'Weline/Demo/view/templates/frontend/widgets/demo.phtml');
        @unlink($tmp);
        self::assertSame([], $issues);
    }

    public function testProductCardRequiresFrame(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-card-' . uniqid('', true) . '.phtml';
        file_put_contents($tmp, '<div class="wpc-media"><img src="x.jpg" width="800" height="800"></div>');
        $issues = $scanner->scanFile($tmp, 'Weline/Product/view/templates/frontend/partials/product-card.phtml');
        @unlink($tmp);
        self::assertNotSame([], $issues);
        self::assertSame(WidgetLayoutStabilityScanner::TYPE_MISSING_FRAME, $issues[0]['type']);
    }

    public function testFrontendWidgetRawImgRequiresFrame(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-widget-' . uniqid('', true) . '.phtml';
        file_put_contents($tmp, '<section><img src="banner.jpg" width="1200" height="400"></section>');
        $issues = $scanner->scanFile($tmp, 'Weline/Theme/view/theme/frontend/widgets/banner/demo/default.phtml');
        @unlink($tmp);
        self::assertNotSame([], $issues);
        self::assertSame(WidgetLayoutStabilityScanner::TYPE_MISSING_FRAME, $issues[0]['type']);
    }

    public function testFrontendWidgetFramedImgPasses(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-widget-ok-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<div class="w-frame" data-ratio="16/9"><img src="banner.jpg" width="1200" height="675"></div>'
        );
        $issues = $scanner->scanFile($tmp, 'Weline/Theme/view/theme/frontend/widgets/banner/demo/default.phtml');
        @unlink($tmp);
        self::assertSame([], $issues);
    }

    public function testLayoutExemptImgPasses(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-exempt-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<img src="icon.png" width="16" height="16" data-layout-exempt="1" class="police-icon">'
        );
        $issues = $scanner->scanFile($tmp, 'Weline/Theme/view/theme/frontend/widgets/footer/demo/default.phtml');
        @unlink($tmp);
        self::assertSame([], $issues);
    }

    public function testPhpEchoInsideImgAttrsStillSeesExempt(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-php-attr-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<img src="<?= $esc($placeholder) ?>" alt="" class="police-icon" width="16" height="16" data-layout-exempt="1">'
        );
        $issues = $scanner->scanFile($tmp, 'Weline/Theme/view/theme/frontend/widgets/footer/demo/default.phtml');
        @unlink($tmp);
        self::assertSame([], $issues);
    }

    public function testDesignThemeWidgetPathIsIncluded(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-design-' . uniqid('', true) . '.phtml';
        file_put_contents($tmp, '<section><img src="banner.jpg" width="1200" height="400"></section>');
        $issues = $scanner->scanFile(
            $tmp,
            'design:Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml',
        );
        @unlink($tmp);
        self::assertNotSame([], $issues);
        self::assertSame(WidgetLayoutStabilityScanner::TYPE_MISSING_FRAME, $issues[0]['type']);
    }

    public function testLogoImageClassIsExempt(): void
    {
        $scanner = new WidgetLayoutStabilityScanner();
        $tmp = sys_get_temp_dir() . '/wls-logo-' . uniqid('', true) . '.phtml';
        file_put_contents(
            $tmp,
            '<img class="logo-image" src="logo.png" width="420" height="159">'
        );
        $issues = $scanner->scanFile($tmp, 'Weline/Theme/view/theme/frontend/widgets/header/logo/default.phtml');
        @unlink($tmp);
        self::assertSame([], $issues);
    }
}
