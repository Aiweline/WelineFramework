<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 页脚扩展槽部件依赖 footer-container 父容器；半安装布局须可自动补齐。
 */
final class FooterLayoutIntegrityContractTest extends TestCase
{
    public function testEnsureFooterContainerPrependsWhenExtensionSlotsUsed(): void
    {
        $layout = [
            'footer' => [
                'label' => '底部区域',
                'widgets' => [
                    [
                        'widget_code' => 'footer-help-center-link',
                        'slot_id' => 'footer-help-links',
                        'is_active' => true,
                    ],
                ],
            ],
        ];

        $repaired = FooterDefaultLinksHelper::ensureFooterContainerInLayout($layout);
        $widgets = $repaired['footer']['widgets'] ?? [];
        self::assertNotEmpty($widgets);
        self::assertSame('footer-container', $widgets[0]['widget_code'] ?? null);
        self::assertSame('footer', $widgets[0]['slot_id'] ?? null);
    }

    public function testEnsureFooterContainerNoOpWhenAlreadyPresent(): void
    {
        $layout = [
            'footer' => [
                'widgets' => [
                    [
                        'widget_code' => 'footer-container',
                        'slot_id' => 'footer',
                        'is_active' => true,
                    ],
                    [
                        'widget_code' => 'footer-blog-link',
                        'slot_id' => 'footer-about-links',
                        'is_active' => true,
                    ],
                ],
            ],
        ];

        $repaired = FooterDefaultLinksHelper::ensureFooterContainerInLayout($layout);
        self::assertCount(2, $repaired['footer']['widgets'] ?? []);
        self::assertSame('footer-container', $repaired['footer']['widgets'][0]['widget_code'] ?? null);
    }

    public function testFooterContainerDeclaresIsContainer(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml'
        );
        self::assertStringContainsString('@widget.is_container {true}', $src);
    }
}
