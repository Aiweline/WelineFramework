<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\Scoped\ThemeAssetEditorContextProducer;

final class ThemeAssetEditorContextProducerTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['frontend.asset','backend.runtime'] as $key) { RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX.$key); }
    }

    public function testAssetEditorPreservesPersistedOwnerVersionReferencesAndBackendRuntime(): void
    {
        self::assertTrue(class_exists(ThemeAssetEditorContextProducer::class), 'Asset editor consumer missing');
        $runtime=new ThemeApplicationContext('system-config','backendglobal','normal','backend',1,'default.default.default','normal',0,0,'zh_Hans_CN');
        $runtime->install();
        $parent=new ThemeContentScope('websites','default.default.default','normal','','en_US');
        $scope=new ThemeContentScope('websites','default.__website__.default','normal','','en_US',$parent);
        $version=new ThemeVersionIdentity(3,'default.__website__.default','normal','frontend',7,'draft',4);
        $refs=['historical-resource'=>['release_id'=>98,'source_scope'=>'default.default.default','intent_revision_id'=>62]];
        $application=$this->producer()->build(3,'frontend',$scope,$version,$refs);
        $application->install();
        self::assertSame($runtime,ThemeApplicationContext::current('backend'));
        self::assertSame($application,ThemeApplicationContext::current('frontend','asset'));
        self::assertSame('default.__website__.default',$application->versionOwnerScope);
        self::assertSame(7,$application->themeVersionId);
        self::assertSame(4,$application->contentRevision);
        self::assertSame('normal',$application->storeMode);
        self::assertSame($refs,$application->contentScopes[0]['resource_references']);
        self::assertSame(['default.__website__.default','default.default.default'],ThemeContentScope::fromApplication($application)->fallbackStorageScopes);
    }

    public function testDisabledActorCannotProduceAssetContext(): void
    {
        self::assertTrue(class_exists(ThemeAssetEditorContextProducer::class),'Asset editor consumer missing');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_asset_editor_actor_required');
        $this->producer(false)->build(3,'frontend',new ThemeContentScope('websites','default.__website__.default','normal','','en_US'));
    }

    public function testVersionFromAnotherOwnerCannotSelectThisAssetDraft(): void
    {
        self::assertTrue(class_exists(ThemeAssetEditorContextProducer::class),'Asset editor consumer missing');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_asset_editor_version_owner_mismatch');
        $this->producer()->build(3,'frontend',new ThemeContentScope('websites','default.__website__.default','normal','','en_US'),
            new ThemeVersionIdentity(3,'other.__website__.default','normal','frontend',7,'draft',4));
    }

    private function producer(bool $enabled=true): ThemeAssetEditorContextProducer
    {
        $actors=$this->createStub(BackendUserContextProviderInterface::class);
        $actors->method('current')->willReturn(new BackendUserContext(12,'editor','','',2,$enabled,false));
        $themes=$this->getMockBuilder(WelineTheme::class)->disableOriginalConstructor()->onlyMethods(['clearData','load','getId'])->addMethods(['clearQuery'])->getMock();
        $themes->method('clearData')->willReturnSelf(); $themes->method('clearQuery')->willReturnSelf();
        $themes->method('load')->willReturnSelf(); $themes->method('getId')->willReturn(3);
        $themeContext=$this->getMockBuilder(ThemeContextService::class)->disableOriginalConstructor()->onlyMethods(['themeSupportsArea'])->getMock();
        $themeContext->method('themeSupportsArea')->willReturn(true);
        return new ThemeAssetEditorContextProducer($actors,$themes,$themeContext);
    }
}
