<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Spec;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Spec\ThemeSpecValidator;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * S4：layout-critical 是内联进 head 的首屏预算，超预算即门禁失败。
 */
final class LayoutCriticalBudgetTest extends TestCase
{
    public function testFrontendLayoutCriticalStaysWithinInlineBudget(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/ui/css/layout-critical-frontend.css';
        self::assertFileExists($file);
        self::assertLessThanOrEqual(ThemeSpecValidator::MAX_LAYOUT_CRITICAL_BYTES, (int)filesize($file));

        $css = (string)file_get_contents($file);
        // 首屏外壳原语必须在内联 CSS 里，否则非阻塞 head 会引入 CLS。
        self::assertStringContainsString('.weline-main-content', $css);
        self::assertStringContainsString('aspect-ratio', $css);
    }

    public function testBackendLayoutCriticalStaysWithinInlineBudget(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/ui/css/layout-critical-backend.css';
        self::assertFileExists($file);
        self::assertLessThanOrEqual(ThemeSpecValidator::MAX_LAYOUT_CRITICAL_BYTES, (int)filesize($file));
    }
}
