<?php
declare(strict_types=1);
namespace Weline\Blog\Test\Unit\View;
use PHPUnit\Framework\TestCase;
final class BlogReviewsPlacementContractTest extends TestCase
{
    public function testNativeReviewsHaveOnlyLayoutPlacement(): void
    {
        $entries = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        foreach ($entries as $widget) {
            if (is_array($widget) && ($widget['code'] ?? '') === 'blog-reviews') {
                self::assertSame('layout', $widget['placement'] ?? null);
                self::assertSame([], $widget['default_injections']);
                $source = file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/blog-reviews.phtml');
                self::assertStringNotContainsString('@widget.default_injections', $source);
                return;
            }
        }
        self::fail('blog-reviews registry declaration missing');
    }
}
