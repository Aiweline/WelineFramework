<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;

final class ThemeContentScopeTest extends TestCase
{
    public function testProviderIsolationPersistsInOwnerAndPath(): void
    {
        $a = new ThemeContentScope('portal', 'main', 'normal', 'Main', 'en_US');
        $b = new ThemeContentScope('admin', 'main', 'normal', 'Main', 'en_US');
        self::assertSame('!external!portal.main', $a->storageScope);
        self::assertNotSame($a->storageScope, $b->storageScope);
        $aOwner = new ThemeVersionIdentity(3, $a->storageScope, $a->storeMode, 'frontend');
        $bOwner = new ThemeVersionIdentity(3, $b->storageScope, $b->storeMode, 'frontend');
        self::assertNotSame($aOwner->ownerHash(), $bOwner->ownerHash());
        self::assertNotSame($aOwner->scopeKey(), $bOwner->scopeKey());
        self::assertStringEndsWith('/Theme/Api/Scoped/ThemeContentScope.php',
            str_replace('\\', '/', (new \ReflectionClass($a))->getFileName()));
        self::assertStringContainsString('/app/code/', (new \ReflectionClass($a))->getFileName());
        self::assertStringContainsString('/app/code/', (new \ReflectionClass(ThemeEditorContext::class))->getFileName());
    }

    public function testWebsiteIdentityKeepsProvenHistoricalBytes(): void
    {
        $scope = new ThemeContentScope('websites', 'shop-a.cn.app', 'test', 'Channel', 'en_US');
        $context = new ThemeEditorContext($scope, 'frontend', 'layout', 19, 'cms_page', 'landing', 'en_US', 'cms_page', 42);
        $oldParts = ['shop-a.cn.app', 'test', 'frontend', 'layout', '19', 'cms_page', 'landing', 'default', 'cms_page', '42'];
        self::assertSame('shop-a.cn.app', $scope->storageScope);
        self::assertSame(hash('sha256', implode("\0", $oldParts)), $context->identityHash());
        $differentLabel = new ThemeContentScope('websites', 'shop-a.cn.app', 'test', 'Renamed', 'zh_Hans_CN');
        self::assertSame($context->identityHash(), $context->withScope($differentLabel)->identityHash());
    }

    public function testOnlyExplicitParentChainIsRetainedAndRoundTrips(): void
    {
        $parent = new ThemeContentScope('portal', 'root', 'test', 'Root', 'en_US');
        $child = new ThemeContentScope('portal', 'child', 'test', 'Child', 'en_US', $parent);
        self::assertSame(['!external!portal.child', '!external!portal.root'], $child->fallbackStorageScopes);
        self::assertSame($child->toArray(), ThemeContentScope::fromArray($child->toArray())->toArray());
        $isolated = new ThemeContentScope('portal', 'some.deep.range', 'test', 'Other', 'en_US');
        self::assertSame(['!external!portal.some.deep.range'], $isolated->fallbackStorageScopes);
    }

    public function testModeOptionTargetAndLocaleIsolationSurviveCopies(): void
    {
        $scope = new ThemeContentScope('portal', 'main', 'normal', 'Main', 'en_US');
        $context = new ThemeEditorContext($scope, 'frontend', 'layout', 3, 'account/login', 'landing', 'en_US', 'cms_page', 42);
        $test = new ThemeContentScope('portal', 'main', 'test', 'Main', 'en_US');
        self::assertNotSame($context->identityHash(), $context->withScope($test)->identityHash());
        self::assertSame($context->identityHash(), $context->withLocale('zh_Hans_CN')->identityHash());
        $i18n = $context->withResource('i18n');
        self::assertNotSame($i18n->identityHash(), $i18n->withLocale('zh_Hans_CN')->identityHash());
        self::assertSame('landing', $i18n->layoutOption);
        self::assertSame(42, $i18n->targetId);
        self::assertSame('portal', $context->withLayoutType('checkout/success')->scope->provider);
    }

    public function testReservedExternalPrefixCannotMasqueradeAsLegacyWebsite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeContentScope('websites', '!external!portal.main', 'normal', '', 'en_US');
    }

    public function testSuppliedParentCannotCrossMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeContentScope('portal', 'child', 'normal', '', 'en_US',
            new ThemeContentScope('portal', 'root', 'test', '', 'en_US'));
    }

    public function testTamperedSerializedStorageIdentityIsRejected(): void
    {
        $scope = new ThemeContentScope('portal', 'main', 'normal', '', 'en_US');
        $data = $scope->toArray();
        $data['storage_scope'] = '!external!admin.main';
        $this->expectException(\InvalidArgumentException::class);
        ThemeContentScope::fromArray($data);
    }
}
