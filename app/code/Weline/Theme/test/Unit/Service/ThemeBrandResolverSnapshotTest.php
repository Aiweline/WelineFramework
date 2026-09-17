<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemePublishedSnapshotReaderInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Service\ThemeBrandResolver;

final class ThemeBrandResolverSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::leave();
        parent::tearDown();
    }

    public function testPublicBrandUsesTheOptionalSnapshotReaderWithoutLoadingEditorData(): void
    {
        $workspace = $this->createMockForIntersectionOfInterfaces([
            ThemeScopedWorkspaceInterface::class, ThemePublishedSnapshotReaderInterface::class,
        ]);
        $workspace->expects(self::never())->method('load');
        $workspace->expects(self::once())->method('readPublishedSnapshot')->willReturn([
            'payload' => ['brand' => ['favicon' => ' /icon.png ', 'logo_light' => '/logo.png']],
            'release_id' => 42, 'source_scope' => 'shop.default.default',
        ]);
        $brand = $this->resolver($workspace)->resolvePublishedBrand('frontend', 19, $this->scope());
        self::assertSame('/icon.png', $brand['favicon']);
        self::assertStringContainsString('/logo.png', $brand['logo_light']);
        self::assertSame('', $brand['logo_dark']);
        self::assertSame('shop.default.default', $brand['source_scope']);
    }

    public function testResolverWalksParentThemeChainInSource(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeBrandResolver.php'
        );
        self::assertStringContainsString('resolveParentThemeId', $src);
        self::assertStringContainsString('子主题 appearance.brand 为空时沿父主题链回落', $src);
        self::assertStringContainsString('readBrandLayer', $src);
        self::assertStringContainsString('filling missing keys from ancestor Scopes', $src);
        self::assertStringContainsString('readBrandAtScope', $src);
        self::assertStringContainsString('parentIdentity', $src);
    }

    public function testEmptyBrandAtChildScopeInheritsFromParentScope(): void
    {
        $child = new ScopeContext(
            ScopeIdentity::website(7, 'shop'),
            'shop.__website__.default',
            ScopeIdentity::MODE_NORMAL,
            ['shop.__website__.default', 'default.default.default'],
        );
        $parent = new ScopeContext(
            ScopeIdentity::global(),
            'default.default.default',
            ScopeIdentity::MODE_NORMAL,
            ['default.default.default'],
        );
        $workspace = $this->createMockForIntersectionOfInterfaces([
            ThemeScopedWorkspaceInterface::class, ThemePublishedSnapshotReaderInterface::class,
        ]);
        $workspace->expects(self::exactly(2))->method('readPublishedSnapshot')->willReturnCallback(
            static function ($context) use ($child, $parent): array {
                $scope = $context->scope->storageScope;
                if ($scope === $child->storageScope) {
                    return [
                        'payload' => ['brand' => []],
                        'release_id' => 10,
                        'source_scope' => $child->storageScope,
                    ];
                }
                if ($scope === $parent->storageScope) {
                    return [
                        'payload' => ['brand' => ['logo_light' => '/from-parent-scope.png']],
                        'release_id' => 2,
                        'source_scope' => $parent->storageScope,
                    ];
                }

                return ['payload' => [], 'release_id' => null, 'source_scope' => 'theme-package-default'];
            }
        );
        $scopes = $this->createMock(ScopeHierarchyInterface::class);
        $scopes->method('parentIdentity')->willReturnCallback(
            static function (ScopeIdentity $identity) use ($child): ?ScopeIdentity {
                return $identity->equals($child->identity) ? ScopeIdentity::global() : null;
            }
        );
        $scopes->method('contextFromIdentity')->willReturnCallback(
            static function (ScopeIdentity $identity) use ($child, $parent): ScopeContext {
                return $identity->scopeKind === ScopeIdentity::KIND_GLOBAL ? $parent : $child;
            }
        );
        $resolver = new ThemeBrandResolver(
            $workspace,
            $scopes,
            $this->createMock(ScopeIdentityCatalogInterface::class),
        );
        $brand = $resolver->resolvePublishedBrand('frontend', 19, $child);
        self::assertSame('/from-parent-scope.png', $brand['logo_light']);
        self::assertSame('default.default.default', $brand['source_scope']);
    }

    public function testOldProvidersContinueUsingPublishedLoad(): void
    {
        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->expects(self::once())->method('load')->with(self::anything(), false)->willReturn([
            'published_payload' => ['brand' => ['favicon' => '/old-provider.png']],
            'published_source_scope' => 'shop.default.default',
        ]);
        self::assertSame('/old-provider.png', $this->resolver($workspace)->resolvePublishedBrand('frontend', 19, $this->scope())['favicon']);
    }

    public function testPreviewKeepsItsDraftReadAndDoesNotUseThePublishedSnapshot(): void
    {
        $workspace = $this->createMockForIntersectionOfInterfaces([
            ThemeScopedWorkspaceInterface::class, ThemePublishedSnapshotReaderInterface::class,
        ]);
        $workspace->expects(self::never())->method('readPublishedSnapshot');
        $workspace->expects(self::once())->method('load')->with(self::anything(), true)->willReturn([
            'draft_payload' => ['brand' => ['favicon' => '/draft.png']],
            'published_payload' => ['brand' => ['favicon' => '/published.png']],
        ]);
        self::assertSame('/draft.png', $this->resolver($workspace)->resolvePublishedBrand('frontend', 19, $this->scope(), true)['favicon']);
    }

    public function testInstalledScopeIsReusedWithoutAnotherCatalogLookup(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request']]));
        RequestContext::setId('brand-installed-scope');
        $scope = $this->scope();
        RequestContext::installScopeIdentity($scope->identity);
        $scopes = $this->createMock(ScopeHierarchyInterface::class);
        $scopes->expects(self::once())->method('contextFromIdentity')->with($scope->identity)->willReturn($scope);
        $catalog = $this->createMock(ScopeIdentityCatalogInterface::class);
        $catalog->expects(self::never())->method('authoritativeIdentity');
        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->method('load')->willReturn(['published_payload' => ['brand' => ['favicon' => '/installed.png']]]);
        $resolver = new ThemeBrandResolver($workspace, $scopes, $catalog);
        self::assertSame('/installed.png', $resolver->resolvePublishedBrand('frontend', 19)['favicon']);
    }

    private function scope(): ScopeContext
    {
        return new ScopeContext(ScopeIdentity::website(7, 'shop'), 'shop.default.default', ScopeIdentity::MODE_NORMAL, ['shop.default.default', 'default.default.default']);
    }

    private function resolver(ThemeScopedWorkspaceInterface $workspace): ThemeBrandResolver
    {
        return new ThemeBrandResolver($workspace, $this->createMock(ScopeHierarchyInterface::class), $this->createMock(ScopeIdentityCatalogInterface::class));
    }
}
