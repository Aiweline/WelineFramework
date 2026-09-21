<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

final class ThemeEditorContextFactoryTest extends TestCase
{
    public function testEditorContextAllowsThemeBesidesDraftBinding(): void
    {
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

        $targetTypes = $this->getMockBuilder(ThemeTargetTypeRegistry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'isValidTarget'])
            ->getMock();
        $provider = $this->createMock(TargetTypeProviderInterface::class);
        $provider->method('canUseLayoutType')->willReturn(true);
        $targetTypes->method('get')->willReturn($provider);
        $targetTypes->method('isValidTarget')->willReturn(true);

        $factory = new ThemeEditorContextFactory(
            new SystemConfigScopeResolver(),
            $catalog,
            $themes,
            $themeContext,
            $targetTypes,
            $workspaces,
        );

        $context = $factory->fromInput([
            'editor_context' => [
                'scope' => ['identity' => ScopeIdentity::global()->toArray()],
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

        self::assertSame(10, $context->themeId);
        self::assertSame('layout', $context->resourceType);
    }

    public function testStorageScopeOnlyResolvesIdentityWithoutMissingFieldError(): void
    {
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
            'draft_payload' => ['theme_id' => 10],
        ]);

        $targetTypes = $this->getMockBuilder(ThemeTargetTypeRegistry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'isValidTarget'])
            ->getMock();
        $provider = $this->createMock(TargetTypeProviderInterface::class);
        $provider->method('canUseLayoutType')->willReturn(true);
        $targetTypes->method('get')->willReturn($provider);
        $targetTypes->method('isValidTarget')->willReturn(true);

        $factory = new ThemeEditorContextFactory(
            new SystemConfigScopeResolver(),
            $catalog,
            $themes,
            $themeContext,
            $targetTypes,
            $workspaces,
        );

        $context = $factory->fromInput([
            'editor_context' => [
                'scope' => ['storage_scope' => 'default.__website__.default'],
                'area' => 'frontend',
                'resource_type' => 'layout',
                'theme_id' => 10,
                'layout_type' => 'homepage',
                'layout_option' => 'default',
                'locale' => 'default',
                'target_type' => 'global',
                'target_id' => 0,
            ],
        ], 'layout');

        self::assertSame('website', $context->scope->identity->scopeKind);
        self::assertSame('default.__website__.default', $context->scope->storageScope);
        self::assertArrayHasKey('scope_kind', $context->scope->identity->toArray());
    }

    public function testIncompleteIdentityFallsBackToStorageScope(): void
    {
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
            'draft_payload' => ['theme_id' => 10],
        ]);

        $targetTypes = $this->getMockBuilder(ThemeTargetTypeRegistry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'isValidTarget'])
            ->getMock();
        $provider = $this->createMock(TargetTypeProviderInterface::class);
        $provider->method('canUseLayoutType')->willReturn(true);
        $targetTypes->method('get')->willReturn($provider);
        $targetTypes->method('isValidTarget')->willReturn(true);

        $factory = new ThemeEditorContextFactory(
            new SystemConfigScopeResolver(),
            $catalog,
            $themes,
            $themeContext,
            $targetTypes,
            $workspaces,
        );

        $context = $factory->fromInput([
            'editor_context' => [
                'scope' => [
                    'identity' => ['website_code' => 'default'],
                    'storage_scope' => 'default.__website__.default',
                ],
                'area' => 'frontend',
                'resource_type' => 'layout',
                'theme_id' => 10,
                'layout_type' => 'homepage',
                'layout_option' => 'default',
                'locale' => 'default',
                'target_type' => 'global',
                'target_id' => 0,
            ],
        ], 'layout');

        self::assertSame('website', $context->scope->identity->scopeKind);
    }
}
