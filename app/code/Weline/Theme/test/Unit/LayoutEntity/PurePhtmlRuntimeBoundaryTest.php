<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
final class PurePhtmlRuntimeBoundaryTest extends TestCase
{
    public function testRenderedPlaceholdersCannotReactivateRuntimeLayoutPlacement(): void
    {
        self::assertFalse(ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill('<main class="slot-placeholder"><div data-wslot="content"></div></main>'));
        self::assertSame('<b>source</b>', ThemeLayoutEntityPublishedSlotHost::publishedInner('content', '<b>source</b>'));
    }
    public function testChromeCompatibilityReaderUsesOnlyThePinnedRequestSources(): void
    {
        \Weline\Framework\Runtime\RequestContext::remove(\Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot::REQUEST_KEY);
        $chrome = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome::class);
        try { $sources = $chrome->resolveRenderSources(999998, 'default.default.default'); }
        catch (\RuntimeException $error) { self::fail('No pinned derived source should select original templates: ' . $error->getMessage()); }
        self::assertSame([], $sources);
        self::assertSame('', $chrome->renderCurrent(3, 'default.default.default'));
    }

    public function testRetiredHeadAssetReaderNeverResolvesVersionsOrSidecars(): void
    {
        $reader = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets::class))->newInstanceWithoutConstructor();
        self::assertSame('', $reader->renderHtmlForCurrentRequest());
    }

    public function testRetiredWidgetEntriesNeverReadPrimedOrSidecarConfiguration(): void
    {
        $renderer = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer::class))->newInstanceWithoutConstructor();
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 4, 'formal', 2);
        $binding = new EntityRenderBinding($identity, 'page', 'homepage', 'structure', 'config', '', '', '', '', '', '');
        self::assertSame('', $renderer->render('legacy', 'page', 3, 'default.default.default', '4'));
        self::assertSame('', $renderer->renderBound('legacy', 'page', $binding));
    }

    public function testLegacyConfigBytesAreNeverReadAsRuntimeAuthority(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'retired-theme-config');
        file_put_contents($path, '{"node":{"config":{"title":"must not leak"}}}');
        try {
            $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 4, 'formal', 2);
            $binding = new EntityRenderBinding($identity, 'page', 'homepage', 'structure', 'config', '', $path, $path, $path, '', '');
            $store = new ThemeLayoutEntityConfigStore(new ThemeLayoutEntityPaths());
            self::assertSame([], $store->readBoundConfig($binding));
            self::assertSame([], $store->readBoundAssets($binding));
        } finally { unlink($path); }
    }
}
