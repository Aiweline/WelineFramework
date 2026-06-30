<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Service\DefaultLayoutSeeder;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeLayoutVersionService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;

class ThemePreviewContentRendererRestoreTest extends TestCore
{
    public function testDraftPreviewRespectsEmptyRestoreVersionWithoutPublishedFallback(): void
    {
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('getFullLayout')->willReturnCallback(
            static function (int $themeId, string $pageType, string $status): array {
                if ($status === ThemeLayout::STATUS_PUBLISHED) {
                    return [
                        'content' => [
                            'widgets' => [
                                [
                                    'widget_code' => 'basic/button',
                                    'slot_id' => 'content',
                                    'area' => 'content',
                                ],
                            ],
                        ],
                    ];
                }

                return [];
            }
        );

        $currentVersion = new ThemeLayoutVersion();
        $currentVersion->setVersionType(ThemeLayoutVersion::TYPE_RESTORE);
        $currentVersion->setSnapshotData([]);

        $versionService = new readonly class($currentVersion) extends ThemeLayoutVersionService {
            public function __construct(private ThemeLayoutVersion $currentVersion)
            {
            }

            public function getCurrentVersion(int $themeId, string $pageType): ?ThemeLayoutVersion
            {
                return $this->currentVersion;
            }
        };

        $defaultLayoutSeeder = $this->createMock(DefaultLayoutSeeder::class);
        $defaultLayoutSeeder->expects($this->never())->method('seedDefaultLayout');

        $slotRenderer = $this->createMock(SlotRendererService::class);
        $slotRenderer->expects($this->never())->method('processSlots');

        $pageTypeResolver = new ThemePageTypeResolver();

        $renderer = new ThemePreviewContentRenderer(
            $layoutService,
            $defaultLayoutSeeder,
            $slotRenderer,
            $pageTypeResolver,
            $versionService,
        );

        $payload = $renderer->build(1, 'codex_restore', ThemeLayout::STATUS_DRAFT);

        $this->assertSame('codex_restore', $payload['page_type']);
        $this->assertSame(ThemeLayout::STATUS_DRAFT, $payload['status']);
        $this->assertSame('', $payload['content']);
        $this->assertSame([], $payload['meta']);
    }
}
