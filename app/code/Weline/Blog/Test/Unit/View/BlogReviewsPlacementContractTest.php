<?php
declare(strict_types=1);
namespace Weline\Blog\Test\Unit\View;
use PHPUnit\Framework\TestCase;
final class BlogReviewsPlacementContractTest extends TestCase
{
    public function testNativeReviewsHaveOnlyLayoutPlacement(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $tpl = 'Weline_Blog::templates/frontend/widgets/blog-reviews.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/blog-reviews.phtml');
        self::assertStringContainsString('@widget.code {blog-reviews}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringNotContainsString('@widget.default_injections', $src);
    }

    public function testReviewsLayoutShellDoesNotDoubleCardChrome(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/blog/default.phtml'
        );
        self::assertStringContainsString('blog-layout__reviews-container', $layout);
        self::assertStringContainsString('background: transparent', $layout);
        self::assertStringContainsString('padding: 0', $layout);
        // 禁止布局壳再套白底边框（与 .weline-review 叠成盒中盒）
        self::assertDoesNotMatchRegularExpression(
            '/\.blog-layout__reviews-container\s*\{[^}]*border:\s*1px/s',
            $layout
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.blog-layout__reviews-container\s*\{[^}]*background:\s*#fff/s',
            $layout
        );
    }
}
