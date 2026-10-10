<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Published layout entities may keep a stale widget_type; renderer must fall back by module+code.
 */
final class ThemeLayoutEntityWidgetTypeFallbackContractTest extends TestCase
{
    public function test_catalog_exposes_module_code_fallback(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ThemeComponentCatalog.php');
        self::assertStringContainsString('function findByModuleCode(', $src);
    }

    public function test_renderer_falls_back_when_typed_find_misses(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php'
        );
        self::assertStringContainsString('findByModuleCode', $src);
        self::assertStringContainsString('stale widget_type', $src);
    }

    public function test_order_notice_registry_type_is_content_not_form(): void
    {
        $tpl = dirname(__DIR__, 4) . '/Order/view/templates/frontend/widgets/order-notice.phtml';
        self::assertFileExists($tpl);
        $src = (string)file_get_contents($tpl);
        self::assertStringContainsString('@widget.type {content}', $src);
        self::assertStringNotContainsString('@widget.type {form}', $src);
    }
}
