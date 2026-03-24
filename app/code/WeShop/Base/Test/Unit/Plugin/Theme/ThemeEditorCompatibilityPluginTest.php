<?php

declare(strict_types=1);

namespace WeShop\Base\Test\Unit\Plugin\Theme;

use PHPUnit\Framework\TestCase;
use WeShop\Base\Plugin\Theme\ThemeEditorCompatibilityPlugin;
use WeShop\Base\Service\ThemeCompatibilityService;
use Weline\Framework\Http\Request;
use Weline\Theme\Controller\Backend\ThemeEditor;

class ThemeEditorCompatibilityPluginTest extends TestCase
{
    public function testAfterPostPublishAppendsCompatibilityPayloadToJsonResponse(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->createMock(ThemeCompatibilityService::class);
        $service->expects($this->once())
            ->method('inspectFromRequest')
            ->with($request)
            ->willReturn([
                'has_missing_hosts' => true,
                'warning_message' => 'Compatibility warning.',
            ]);
        $service->expects($this->once())
            ->method('emitWarning')
            ->with(
                $this->callback(static fn (array $value): bool => !empty($value['has_missing_hosts'])),
                'publish_layout'
            );
        $service->expects($this->once())
            ->method('buildPayload')
            ->with(
                $this->callback(static fn (array $value): bool => !empty($value['has_missing_hosts'])),
                'publish_layout'
            )
            ->willReturn([
                'action' => 'publish_layout',
                'missing_count' => 1,
            ]);

        $plugin = new ThemeEditorCompatibilityPlugin($service, $request);
        $subject = $this->getMockBuilder(ThemeEditor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $result = $plugin->afterPostPublish($subject, json_encode([
            'success' => true,
            'message' => 'Published.',
        ], JSON_UNESCAPED_UNICODE));

        $decoded = json_decode($result, true);

        $this->assertSame('Published. Compatibility warning.', $decoded['message']);
        $this->assertSame('publish_layout', $decoded['compatibility']['action']);
    }

    public function testAfterGetLayoutPreviewInjectsBannerWhenHostsAreMissing(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $service = $this->createMock(ThemeCompatibilityService::class);
        $service->expects($this->once())
            ->method('inspectFromRequest')
            ->with($request)
            ->willReturn([
                'has_missing_hosts' => true,
                'warning_message' => 'Compatibility warning.',
            ]);
        $service->expects($this->once())
            ->method('emitWarning')
            ->with(
                $this->callback(static fn (array $value): bool => !empty($value['has_missing_hosts'])),
                'layout_preview'
            );
        $service->expects($this->once())
            ->method('injectPreviewBanner')
            ->with(
                '<html><body>Preview</body></html>',
                $this->callback(static fn (array $value): bool => !empty($value['has_missing_hosts']))
            )
            ->willReturn('<html><body><div class="banner">Compatibility</div>Preview</body></html>');

        $plugin = new ThemeEditorCompatibilityPlugin($service, $request);
        $subject = $this->getMockBuilder(ThemeEditor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $result = $plugin->afterGetLayoutPreview($subject, '<html><body>Preview</body></html>');

        $this->assertStringContainsString('Compatibility', $result);
    }
}
