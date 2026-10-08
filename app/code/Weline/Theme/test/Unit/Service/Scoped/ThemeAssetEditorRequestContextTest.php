<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;
use Weline\Theme\Service\Scoped\ThemeAssetEditorContextProducer;
use Weline\Theme\Service\Scoped\ThemeAssetEditorRequestContext;

final class ThemeAssetEditorRequestContextTest extends TestCase
{
    protected function tearDown(): void {
        RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX.'frontend.asset');
        RequestContext::remove('theme.scope_version.selection.3|default.__website__.default|normal|frontend');
        RequestContext::remove('theme.scope_version.flag.3|default.__website__.default|normal|frontend|is_current');
    }

    public function testEachIndependentJsonRequestResolvesTypedScopeBeforeInstallingAssetContext(): void
    {
        self::assertTrue(class_exists(ThemeAssetEditorRequestContext::class),'Asset request adapter missing');
        $input=['editor_context'=>['theme_id'=>3,'area'=>'frontend','scope'=>['identity'=>ScopeIdentity::website(0,'default')->toArray()],
            'layout_type'=>'homepage','layout_option'=>'default','target_type'=>'global','target_id'=>0],'unrelated'=>false];
        $adapter=$this->adapter();
        for ($i=0;$i<2;$i++) {
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX.'frontend.asset');
            $result=$adapter->prepare($input,static fn(array $identity):string=>'en_US');
            self::assertSame('default.__website__.default',$result['editor_context']['scope']['storage_scope']);
            self::assertSame('normal',$result['editor_context']['scope']['store_mode']);
            self::assertFalse($result['unrelated']);
            $context=ThemeApplicationContext::current('frontend','asset');
            self::assertSame('default.__website__.default',$context->versionOwnerScope);
            self::assertSame('en_US',$context->defaultLocale);
        }
    }

    public function testClientStorageClaimCannotOverrideServerCanonicalIdentity(): void
    {
        self::assertTrue(class_exists(ThemeAssetEditorRequestContext::class),'Asset request adapter missing');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_asset_editor_scope_mismatch');
        try {
            $this->adapter()->prepare(['editor_context'=>['theme_id'=>3,'area'=>'frontend','scope'=>[
                'identity'=>ScopeIdentity::website(0,'default')->toArray(),'storage_scope'=>'other.__website__.default']]],static fn(array $identity):string=>'en_US');
        } finally { self::assertNull(ThemeApplicationContext::current('frontend','asset')); }
    }

    public function testThemeBindingChangeValueFallbackIsPresentInRequestContext(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/Service/Scoped/ThemeAssetEditorRequestContext.php');
        self::assertNotFalse($source);
        self::assertStringContainsString("\$path === '/theme_id' || \$path === 'theme_id'", $source);
        self::assertStringContainsString('frontend_theme_id', $source);
        self::assertStringContainsString('theme_asset_editor_theme_unavailable', $source);
    }

    private function adapter(): ThemeAssetEditorRequestContext
    {
        $catalog=$this->createStub(ScopeIdentityCatalogInterface::class);
        $catalog->method('authoritativeIdentity')->willReturnCallback(static fn(ScopeIdentity $identity)=>$identity);
        $actors=$this->createStub(BackendUserContextProviderInterface::class);
        $actors->method('current')->willReturn(new BackendUserContext(12,'editor','','',2,true,false));
        $themes=$this->getMockBuilder(WelineTheme::class)->disableOriginalConstructor()->onlyMethods(['clearData','load','getId'])->addMethods(['clearQuery'])->getMock();
        $themes->method('clearData')->willReturnSelf(); $themes->method('clearQuery')->willReturnSelf(); $themes->method('load')->willReturnSelf(); $themes->method('getId')->willReturn(3);
        $themeContext=$this->getMockBuilder(ThemeContextService::class)->disableOriginalConstructor()->onlyMethods(['themeSupportsArea'])->getMock();
        $themeContext->method('themeSupportsArea')->willReturn(true);
        RequestContext::set('theme.scope_version.selection.3|default.__website__.default|normal|frontend',['selection'=>null]);
        RequestContext::set('theme.scope_version.flag.3|default.__website__.default|normal|frontend|is_current',['version'=>null]);
        $versions=new ThemeScopeVersionService((new \ReflectionClass(\Weline\Theme\Model\ThemeScopeVersion::class))->newInstanceWithoutConstructor());
        return new ThemeAssetEditorRequestContext($catalog,new SystemConfigScopeResolver(),new ThemeAssetEditorContextProducer($actors,$themes,$themeContext),$versions,new ThemeVersionResourceSnapshotService());
    }
}
