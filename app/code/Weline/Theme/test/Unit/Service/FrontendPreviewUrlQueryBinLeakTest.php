<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * Regression: start-preview via BinQuery must not mint /framework/query-bin?weline_preview_token=.
 */
final class FrontendPreviewUrlQueryBinLeakTest extends TestCase
{
    private array $serverBackup;
    private array $envSnapshot;
    private array $runtimeConfig;
    private array $instances;
    private ?Context $previousContext;
    private Url $url;
    private ThemePageTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
        $this->runtimeConfig = (new ReflectionClass(Env::class))->getProperty('runtimeConfig')->getValue(Env::getInstance());
        $this->instances = ObjectManager::getInstances();
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
        $_SERVER = [
            'HTTP_HOST' => 'fixture.test',
            'REQUEST_SCHEME' => 'https',
            'REQUEST_URI' => '/framework/query-bin',
            'WELINE_ORIGIN_REQUEST_URI' => '/api/framework/query-bin',
            'WELINE_WEBSITE_URL' => 'https://fixture.test',
        ];
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', 'https://fixture.test', 'preview query-bin leak fixture');
        WelineEnv::set('website.currency', 'CNY', 'preview query-bin leak fixture');
        WelineEnv::set('website.language', 'zh_Hans_CN', 'preview query-bin leak fixture');
        Env::getInstance()->applyRuntimeConfig(['seo' => false, 'currency' => 'CNY', 'locale' => 'zh_Hans_CN']);
        RequestContext::setId('frontend-preview-query-bin-leak');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            ScopeIdentity::channel(7, 'fixture', 'store', 'channel', ScopeIdentity::MODE_NORMAL),
            'zh_Hans_CN',
            'CNY',
            hash('sha256', 'fixture-namespaces'),
            hash('sha256', 'fixture-scope'),
            true,
        ));

        $request = $this->createMock(Request::class);
        $request->method('getBaseHost')->willReturn('https://fixture.test');
        // Current request is query-bin — this is what getFrontendUrl('') wrongly reused.
        $request->method('getBaseUrl')->willReturn('https://fixture.test/framework/query-bin');
        $request->method('getRouterData')->with('router')->willReturn('');
        $request->method('getGet')->willReturn([]);
        ObjectManager::setInstance(Request::class, $request);
        ObjectManager::setInstance(EventsManager::class, new class {
            public function dispatch(string $event, mixed &$data = null): void
            {
            }
        });
        $this->url = new Url($request);
        $this->resolver = new ThemePageTypeResolver();
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Env::class);
        $reflection->getProperty('runtimeConfig')->setValue(Env::getInstance(), $this->runtimeConfig);
        $reflection->getMethod('rebuildEffectiveConfig')->invoke(Env::getInstance());
        (new ReflectionClass(ObjectManager::class))->getMethod('setScopedInstances')->invoke(null, $this->instances);
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
        parent::tearDown();
    }

    public function testEmptyFrontendUrlLeaksQueryBinRequestUri(): void
    {
        self::assertSame(
            'https://fixture.test/framework/query-bin',
            $this->url->getFrontendUrl('')
        );
    }

    public function testSafeHomepagePathBuildsStorefrontRootNotQueryBin(): void
    {
        $path = $this->resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME);
        self::assertSame('/', $path);

        $baseUrl = $this->url->getFrontendUrl($path);
        self::assertSame('https://fixture.test/', $baseUrl);
        self::assertStringNotContainsString('query-bin', $baseUrl);

        $previewUrl = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?')
            . PreviewTokenService::TOKEN_KEY . '=' . rawurlencode('pv_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        self::assertStringStartsWith('https://fixture.test/?weline_preview_token=', $previewUrl);
        self::assertStringNotContainsString('framework/query-bin', $previewUrl);
    }
}
