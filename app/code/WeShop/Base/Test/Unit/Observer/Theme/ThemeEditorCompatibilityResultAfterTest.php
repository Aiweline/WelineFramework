<?php

declare(strict_types=1);

namespace WeShop\Base\Test\Unit\Observer\Theme;

use PHPUnit\Framework\TestCase;
use WeShop\Base\Observer\Theme\ThemeEditorCompatibilityResultAfter;
use WeShop\Base\Service\ThemeCompatibilityService;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

class ThemeEditorCompatibilityResultAfterTest extends TestCase
{
    public function testExecuteAppendsCompatibilityPayloadToJsonResponse(): void
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

        $observer = new ThemeEditorCompatibilityResultAfter($service, $request);
        $event = new Event(['data' => new DataObject([
            'action' => 'publish_layout',
            'request' => $request,
            'result' => json_encode([
                'success' => true,
                'message' => 'Published.',
            ], JSON_UNESCAPED_UNICODE),
        ])]);

        $observer->execute($event);

        $decoded = json_decode((string)$event->getData('result'), true);

        $this->assertSame('Published. Compatibility warning.', $decoded['message']);
        $this->assertSame('publish_layout', $decoded['compatibility']['action']);
    }

    public function testExecuteInjectsBannerIntoLayoutPreviewWhenHostsAreMissing(): void
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

        $observer = new ThemeEditorCompatibilityResultAfter($service, $request);
        $event = new Event(['data' => new DataObject([
            'action' => 'layout_preview',
            'request' => $request,
            'result' => '<html><body>Preview</body></html>',
        ])]);

        $observer->execute($event);

        $this->assertStringContainsString('Compatibility', (string)$event->getData('result'));
    }
}
