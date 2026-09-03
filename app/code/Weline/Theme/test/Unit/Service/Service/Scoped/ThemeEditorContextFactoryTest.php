<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

final class ThemeEditorContextFactoryTest extends TestCase
{
    public function testDownstreamThemeMustMatchTheScopeDraftBinding(): void
    {
        $identity = ScopeIdentity::global();
        $scopes = $this->createMock(ScopeHierarchyInterface::class);
        $scopes->method('contextFromClaims')->willReturn(new ScopeContext(
            identity: $identity,
            storageScope: 'default.default.default',
            storeMode: ScopeIdentity::MODE_NORMAL,
            fallbackStorageScopes: ['default.default.default'],
        ));

        $catalog = $this->createMock(ScopeIdentityCatalogInterface::class);
        $catalog->method('authoritativeIdentity')->willReturnCallback(
            static fn(ScopeIdentity $identity): ScopeIdentity => $identity,
        );

        $themes = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getId'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $themes->method('clearData')->willReturnSelf();
        $themes->method('clearQuery')->willReturnSelf();
        $themes->method('load')->willReturnSelf();
        $themes->method('getId')->willReturn(10);

        $themeContext = $this->getMockBuilder(ThemeContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['themeSupportsArea'])
            ->getMock();
        $themeContext->method('themeSupportsArea')->willReturn(true);

        $workspaces = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspaces->method('load')->willReturn([
            'draft_payload' => ['theme_id' => 11],
        ]);

        $factory = new ThemeEditorContextFactory(
            $scopes,
            $catalog,
            $themes,
            $themeContext,
            $this->getMockBuilder(ThemeTargetTypeRegistry::class)->disableOriginalConstructor()->getMock(),
            $workspaces,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_editor_context_theme_scope_mismatch');

        $factory->fromInput([
            'editor_context' => [
                'scope' => ['identity' => $identity->toArray()],
                'area' => 'frontend',
                'resource_type' => 'layout',
                'theme_id' => 10,
                'layout_type' => 'homepage',
                'layout_option' => 'default',
                'locale' => 'default',
                'target_type' => 'global',
                'target_id' => 0,
            ],
        ]);
    }
}
