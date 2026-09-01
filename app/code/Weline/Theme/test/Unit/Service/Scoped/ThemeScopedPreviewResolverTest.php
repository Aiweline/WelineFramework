<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemeNodePlacementResolver;

final class ThemeScopedPreviewResolverTest extends TestCase
{
    public function testDenormalizedWidgetKeepsNodeUidWithoutLegacyProjection(): void
    {
        $uid = '49a36cbf0f004dc748959f00ffe00a30';
        $payload = [
            'theme_id' => 1,
            'selection' => ['layout_option' => 'default'],
            'nodes' => [
                $uid => [
                    'node_uid' => $uid,
                    'area' => ThemeLayout::AREA_CONTENT,
                    'slot_id' => 'content',
                    'widget_code' => 'image-text',
                    'widget_module' => 'Weline_Theme',
                    'widget_type' => 'content',
                    'config' => ['title' => 'Hello'],
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            ],
        ];

        $normalizer = new ThemeLayoutSnapshotNormalizer(new ThemeNodePlacementResolver());
        $layout = $normalizer->denormalize(
            new \Weline\Theme\Api\Scoped\ThemeEditorContext(
                scope: new \Weline\SystemConfig\Api\Scope\ScopeContext(
                    \Weline\Framework\Runtime\ScopeIdentity::global(),
                    'default.default.default',
                    \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL,
                    ['default.default.default'],
                ),
                area: 'frontend',
                resourceType: \Weline\Theme\Api\Scoped\ThemeEditorContext::RESOURCE_LAYOUT,
                themeId: 1,
                layoutType: ThemeLayout::PAGE_TYPE_HOME,
            ),
            $payload,
        );

        $widget = $layout[ThemeLayout::AREA_CONTENT]['widgets'][0];
        self::assertSame($uid, $widget['node_uid']);
        self::assertArrayNotHasKey('layout_id', $widget);
    }
}
