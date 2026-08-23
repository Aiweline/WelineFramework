<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;

final class ThemeEditorContextTest extends TestCase
{
    public function testChannelIdentityAndDownstreamSelectorsRemainCanonical(): void
    {
        $scope = new ScopeContext(
            identity: ScopeIdentity::channel(7, 'shop', 'cn', 'app', ScopeIdentity::MODE_TEST),
            storageScope: 'shop.cn.app',
            storeMode: ScopeIdentity::MODE_TEST,
            fallbackStorageScopes: [
                'shop.cn.app',
                'shop.cn.default',
                'shop.default.default',
                'default.default.default',
            ],
        );
        $context = new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 19,
            layoutType: 'cms_page',
            layoutOption: 'landing',
            locale: 'zh_Hans_CN',
            targetType: 'cms_page',
            targetId: 42,
        );

        $serialized = $context->toArray();

        self::assertSame('channel', $serialized['scope']['identity']['scope_kind']);
        self::assertSame('shop.cn.app', $serialized['scope']['storage_scope']);
        self::assertSame('test', $serialized['scope']['store_mode']);
        self::assertSame(19, $serialized['theme_id']);
        self::assertSame('cms_page', $serialized['layout_type']);
        self::assertSame('landing', $serialized['layout_option']);
        self::assertSame('zh_Hans_CN', $serialized['locale']);
        self::assertSame('cms_page', $serialized['target_type']);
        self::assertSame(42, $serialized['target_id']);
        self::assertSame($context->identityHash(), $serialized['identity_hash']);
    }

    public function testThemeBindingDropsEveryDownstreamSelector(): void
    {
        $scope = new ScopeContext(
            identity: ScopeIdentity::store(7, 'shop', 'cn', ScopeIdentity::MODE_NORMAL),
            storageScope: 'shop.cn.default',
            storeMode: ScopeIdentity::MODE_NORMAL,
            fallbackStorageScopes: [
                'shop.cn.default',
                'shop.default.default',
                'default.default.default',
            ],
        );
        $layout = new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 19,
            layoutType: 'product',
            layoutOption: 'gallery',
            locale: 'en_US',
            targetType: 'product',
            targetId: 9,
        );

        $binding = $layout->withResource(ThemeEditorContext::RESOURCE_THEME_BINDING)->toArray();

        self::assertSame(0, $binding['theme_id']);
        self::assertSame('default', $binding['layout_type']);
        self::assertSame('default', $binding['layout_option']);
        self::assertSame('default', $binding['locale']);
        self::assertSame('global', $binding['target_type']);
        self::assertSame(0, $binding['target_id']);
    }
}
