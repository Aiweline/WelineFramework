<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Helper\EnsurePublishedThemeStaticUrl;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeStaticAssetPublisher;

/**
 * Design-theme brand kit (favicon/logo) must land in pub/static so every Host of
 * the same Website serves the same site brand icon (not ops media, not Host-keyed).
 */
class EnsurePublishedThemeStaticUrlContractTest extends TestCore
{
    private const FIXTURE_THEME_ID = 990874;

    private const SOURCE_FAVICON = 'app/design/Weline/hanfu/frontend/assets/images/component-kit/brand/favicon-32.png';

    private const SOURCE_LOGO = 'app/design/Weline/hanfu/frontend/assets/images/component-kit/brand/approved-logo-reference.png';

    private const PUBLISHED_FAVICON = 'Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/favicon-32.png';

    private const PUBLISHED_LOGO = 'Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/approved-logo-reference.png';

    private ThemeStaticAssetPublisher $publisher;

    public function setUp(): void
    {
        parent::setUp();
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setServer('REQUEST_URI', '/test');
        $request->setGet('frontend_theme_id', 0);
        $request->setGet('backend_theme_id', 0);
        $request->setGet('editor_area', '');
        $request->setGet('shell', '');
        $request->setGet('preview_mode', '');
        $request->setGet('status', '');
        $request->setGet(PreviewTokenService::TOKEN_KEY, '');
        PreviewTokenService::resetRequestState();
        ObjectManager::getInstance(ThemeDirectoryResolver::class)->clearCache();
        $this->publisher = ObjectManager::getInstance(ThemeStaticAssetPublisher::class);
    }

    public function tearDown(): void
    {
        $this->removePublished(self::PUBLISHED_FAVICON);
        $this->removePublished(self::PUBLISHED_LOGO);
        parent::tearDown();
    }

    public function testEnsurePublishesHanfuBrandFaviconIntoPubStatic(): void
    {
        $base = rtrim((string)BP, '\\/') . DIRECTORY_SEPARATOR;
        $source = $base . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_FAVICON);
        if (!is_file($source)) {
            self::markTestSkipped('hanfu design brand favicon missing: ' . self::SOURCE_FAVICON);
        }

        $this->removePublished(self::PUBLISHED_FAVICON);
        $theme = $this->buildHanfuTheme();

        $requestPath = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/favicon-32.png';
        $published = $this->publisher->publishForRequestPath($requestPath, $theme);
        self::assertSame('/pub/static/' . self::PUBLISHED_FAVICON, $published);

        $url = $requestPath . '?v=contract';
        self::assertSame($url, EnsurePublishedThemeStaticUrl::ensure($url));

        $disk = $base . 'pub' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLISHED_FAVICON);
        self::assertFileExists($disk);
        self::assertSame(file_get_contents($source), file_get_contents($disk));
    }

    public function testEnsurePublishesHanfuApprovedLogoIntoPubStatic(): void
    {
        $base = rtrim((string)BP, '\\/') . DIRECTORY_SEPARATOR;
        $source = $base . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_LOGO);
        if (!is_file($source)) {
            self::markTestSkipped('hanfu design approved logo missing: ' . self::SOURCE_LOGO);
        }

        $this->removePublished(self::PUBLISHED_LOGO);
        $theme = $this->buildHanfuTheme();

        $requestPath = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/approved-logo-reference.png';
        $published = $this->publisher->publishForRequestPath($requestPath, $theme);
        self::assertSame('/pub/static/' . self::PUBLISHED_LOGO, $published);
        self::assertSame($requestPath, EnsurePublishedThemeStaticUrl::ensure($requestPath));

        $disk = $base . 'pub' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::PUBLISHED_LOGO);
        self::assertFileExists($disk);
        self::assertSame(file_get_contents($source), file_get_contents($disk));
    }

    public function testEnsureReturnsEmptyWhenPathMissing(): void
    {
        $missing = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/brand/__missing-favicon__.png';
        self::assertSame('', EnsurePublishedThemeStaticUrl::ensure($missing));
    }

    private function buildHanfuTheme(): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery();
        $theme->setData(WelineTheme::schema_fields_ID, self::FIXTURE_THEME_ID);
        $theme->setData(WelineTheme::schema_fields_NAME, 'hanfu');
        $theme->setData(WelineTheme::schema_fields_PATH, 'Weline/hanfu');

        $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        foreach (['frontend', 'backend'] as $fixtureArea) {
            $hotCache->forgetPolicy(
                StorefrontThemeCacheCoordinator::themeAreaDirectoriesPolicy(),
                self::FIXTURE_THEME_ID . '|' . $fixtureArea
            );
        }

        return $theme;
    }

    private function removePublished(string $relativeUnderPubStatic): void
    {
        $staticRoot = realpath(rtrim((string)BP, '\\/') . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'static');
        if ($staticRoot === false) {
            return;
        }
        $resolved = $staticRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeUnderPubStatic);
        if (is_file($resolved)) {
            @unlink($resolved);
        }
        $dir = dirname($resolved);
        while (str_starts_with($dir, $staticRoot . DIRECTORY_SEPARATOR)) {
            if (!@rmdir($dir)) {
                break;
            }
            $dir = dirname($dir);
        }
    }
}
