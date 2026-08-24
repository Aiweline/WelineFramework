<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\Disk\ThemeTokenCatalogService;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemeNodePlacementResolver;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeLayoutService;

final class ThemeScopedPreviewResolverTest extends TestCase
{
    public function testLocaleOverlayIsWrittenBackToTheResolvedWidgetConfig(): void
    {
        $uid = '49a36cbf0f004dc748959f00ffe00a30';
        $image = [
            'type' => 'file-image',
            'usage' => [
                'version' => 1,
                'asset_id' => 'c0bda7b8-470a-4172-ac87-deaac3880610',
                'locale_code' => 'zh_Hans_CN',
                'alt' => 'CMS 统一存储与 OSS 适配器验收图标',
                'alt_state' => 'confirmed',
                'decorative' => false,
                'caption' => null,
                'loading' => 'lazy',
                'priority' => 'auto',
                'widths' => [480, 768, 1280],
                'sizes' => '100vw',
            ],
        ];
        $scopeResolver = new SystemConfigScopeResolver();
        $scopeIdentity = ScopeIdentity::store(
            0,
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        $context = new ThemeEditorContext(
            scope: new ScopeContext(
                $scopeIdentity,
                'default.__store__.default',
                ScopeIdentity::MODE_NORMAL,
                ['default.__store__.default'],
            ),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 1,
            layoutType: ThemeLayout::PAGE_TYPE_CMS,
            layoutOption: 'default',
            locale: 'zh_Hans_CN',
            targetType: 'cms_page',
            targetId: 2,
        );
        $layoutPayload = [
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
                    'config' => [],
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            ],
        ];

        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->method('load')->willReturnCallback(
            static fn(ThemeEditorContext $requested): array =>
                $requested->resourceType === ThemeEditorContext::RESOURCE_I18N
                    ? ['draft_payload' => ['translations' => [$uid => ['image' => $image]]]]
                    : ['draft_payload' => $layoutPayload],
        );
        $adapter = $this->createMock(ThemeScopedResourceAdapterInterface::class);
        $adapter->expects(self::once())->method('projectDraft')->with($context, $layoutPayload);
        $layouts = $this->createMock(ThemeLayoutService::class);
        $layouts->method('getFullLayout')->willReturn([
            ThemeLayout::AREA_CONTENT => [
                'widgets' => [['node_uid' => $uid, 'layout_id' => 871]],
            ],
        ]);
        $layouts->method('decorateLayoutForRender')->willReturnCallback(
            static fn(array $layout): array => $layout,
        );

        $resolver = new ThemeScopedPreviewResolver(
            $workspace,
            $adapter,
            new ThemeLayoutSnapshotNormalizer(new ThemeNodePlacementResolver()),
            $layouts,
            new ThemeLayoutScopeNormalizer($scopeResolver),
            $this->createMock(ThemeTokenCatalogService::class),
            $this->createMock(CssVariableInjector::class),
        );

        $resolved = $resolver->resolveLayout($context, ThemeLayout::STATUS_DRAFT);
        $widget = $resolved[ThemeLayout::AREA_CONTENT]['widgets'][0];

        self::assertSame($image, $widget['config']['image']);
        self::assertTrue($widget['config']['_skip_translation_merge']);
        self::assertSame('zh_Hans_CN', $widget['locale_code']);
        self::assertSame(871, $widget['layout_id']);
    }
}
