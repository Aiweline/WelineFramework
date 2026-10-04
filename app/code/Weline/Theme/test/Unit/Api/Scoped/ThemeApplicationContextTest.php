<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;

final class ThemeApplicationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['frontend','backend'] as $area) {
            foreach (['runtime','editor','preview','asset'] as $purpose) {
                RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . $area . '.' . $purpose);
            }
        }
    }

    public function testInstalledSelectionDoesNotChangeMidRequest(): void
    {
        $context = $this->context();
        $context->install();
        self::assertSame($context, ThemeApplicationContext::current('frontend'));
        self::assertStringContainsString('/app/code/', (new \ReflectionClass($context))->getFileName());
        $this->expectException(\LogicException::class);
        $this->context(themeId: 9)->install();
    }

    public function testExplicitAncestryAndHistoryReferencesRoundTrip(): void
    {
        $context = $this->context(scopes: [
            ['provider'=>'portal', 'scope_key'=>'child', 'store_mode'=>'test', 'display_name'=>'Child', 'default_locale'=>'en_US',
                'resource_references'=>['resource-hash'=>['theme_version_id'=>12,'content_revision'=>4,'release_id'=>31]]],
            ['provider'=>'portal', 'scope_key'=>'root', 'store_mode'=>'test', 'display_name'=>'Root', 'default_locale'=>'en_US'],
        ]);
        self::assertSame($context->toArray(), ThemeApplicationContext::fromArray($context->toArray())->toArray());
        $scope = ThemeContentScope::fromApplication($context);
        self::assertSame(['!external!portal.child', '!external!portal.root'], $scope->fallbackStorageScopes);
        self::assertSame(31, $context->contentScopes[0]['resource_references']['resource-hash']['release_id']);
    }

    public function testNoInstalledContextDoesNotInventDefaultScope(): void
    {
        self::assertNull(ThemeApplicationContext::current('frontend'));
    }

    public function testBackendAssetEditingCannotChangeBackendChrome(): void
    {
        $runtime = new ThemeApplicationContext('system-config', 'admin', 'normal', 'backend', 3,
            '!external!system-config.admin', 'normal', 0, 0, 'en_US');
        $asset = new ThemeApplicationContext('theme-assets', 'sample', 'normal', 'backend', 9,
            '!external!theme-assets.sample', 'normal', 0, 0, 'en_US', purpose:'asset');
        $runtime->install();
        $asset->install();
        self::assertSame($runtime, ThemeApplicationContext::current('backend'));
        self::assertSame($asset, ThemeApplicationContext::current('backend', 'asset'));
    }

    private function context(int $themeId = 3, array $scopes = []): ThemeApplicationContext
    {
        return new ThemeApplicationContext(
            provider:'portal', scopeKey:'child', storeMode:'test', area:'frontend', themeId:$themeId,
            versionOwnerScope:'!external!portal.root', versionOwnerStoreMode:'test', themeVersionId:12,
            contentRevision:4, defaultLocale:'en_US', displayName:'Child', contentScopes:$scopes,
        );
    }
}
