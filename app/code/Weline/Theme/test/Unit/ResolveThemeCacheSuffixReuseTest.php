<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ResolveThemeCacheSuffix;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;

final class ResolveThemeCacheSuffixReuseTest extends TestCase
{
    private Request $originalRequest;
    private WelineTheme $originalTheme;
    private CacheSuffixRequest $request;
    private ResolveThemeCacheSuffix $observer;
    private bool $validPreview = false;
    private string $token = 'pv_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->originalRequest = ObjectManager::getInstance(Request::class);
        $this->originalTheme = ObjectManager::getInstance(WelineTheme::class);
        $this->request = new CacheSuffixRequest();
        ObjectManager::setInstance(Request::class, $this->request);
        $theme = new CacheSuffixTheme();
        ObjectManager::setInstance(WelineTheme::class, $theme);
        $this->enterContext('one');
        $preview = $this->createMock(PreviewTokenService::class);
        $preview->method('isPreviewMode')->willReturnCallback(fn(): bool => $this->validPreview);
        $preview->method('getTokenFromRequest')->willReturnCallback(fn(): string => $this->token);
        $fallback = $this->createMock(ThemeContextService::class);
        $fallback->method('resolveTheme')->willReturn((new CacheSuffixTheme())->setData(['id' => 90, 'path' => '/fallback/']));
        $this->observer = new ResolveThemeCacheSuffix($fallback, $preview);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(Request::class, $this->originalRequest);
        ObjectManager::setInstance(WelineTheme::class, $this->originalTheme);
        RequestContext::cleanup();
        Context::leave();
    }

    public function testRepeatedFilesReuseRequestInterpretationWithoutCachingTheFileSuffix(): void
    {
        self::assertSame('theme_id:1|theme_path:/first/|file:' . md5('first.phtml'), $this->suffix('first.phtml'));
        self::assertSame('theme_id:1|theme_path:/first/|file:' . md5('second.phtml'), $this->suffix('second.phtml'));
        self::assertSame(1, $this->request->pathReads);
        $this->enterContext('two');
        $this->suffix('first.phtml');
        self::assertSame(2, $this->request->pathReads);
    }

    public function testExplicitPreviewInputsAndUriChangesRefreshTheRequestInterpretation(): void
    {
        $this->suffix('page.phtml');
        $this->request->setData(['preview_mode' => true, 'theme_id' => 7]);
        self::assertStringContainsString('theme_id:7|theme_path:/theme-7/|request_theme:frontend:7', $this->suffix('page.phtml'));
        $this->request->setData('theme_id', 8);
        self::assertStringContainsString('theme_id:8|theme_path:/theme-8/|request_theme:frontend:8', $this->suffix('page.phtml'));
        $this->request->unsetData('preview_mode');
        $this->request->setData(['theme_id' => 9]);
        $this->request->uri = '/theme/editor';
        self::assertStringContainsString('request_theme:frontend:9', $this->suffix('page.phtml'));
        $this->request->uri = '/products';
        self::assertStringNotContainsString('request_theme:', $this->suffix('page.phtml'));
    }

    public function testCurrentThemeAreaLocaleAndScopeChangesRemainVisible(): void
    {
        $this->suffix('page.phtml');
        $this->installTheme(2, '/second/');
        self::assertStringContainsString('theme_id:2|theme_path:/second/', $this->suffix('page.phtml'));
        self::assertStringContainsString('theme_id:90|theme_path:/fallback/', $this->suffix('page.phtml', 'backend'));
        State::setRequestLanguageOverride('ar_SA');
        $this->installTheme(3, '/arabic/');
        self::assertStringContainsString('theme_id:3|theme_path:/arabic/', $this->suffix('page.phtml'));
        $this->enterContext('scope-two', ScopeIdentity::website(2, 'second'));
        $this->installTheme(4, '/second-scope/');
        self::assertStringContainsString('theme_id:4|theme_path:/second-scope/', $this->suffix('page.phtml'));
        self::assertSame(4, $this->request->pathReads);
    }

    public function testPreviewTokenIsRevalidatedEvenWhenRequestInterpretationIsReused(): void
    {
        $this->validPreview = true;
        self::assertStringContainsString('preview_token:' . substr($this->token, 0, 16), $this->suffix('page.phtml'));
        $this->token = 'pv_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        self::assertStringContainsString('preview_token:' . substr($this->token, 0, 16), $this->suffix('page.phtml'));
        $this->validPreview = false;
        self::assertStringNotContainsString('preview_token:', $this->suffix('page.phtml'));
        self::assertSame(1, $this->request->pathReads);
    }

    public function testUnavailableRequestPathIsRetriedWithoutMemoizingItsFallback(): void
    {
        $this->request->uri = '/theme/editor';
        $this->request->setData('theme_id', 7);
        $this->request->failPath = true;
        self::assertStringNotContainsString('request_theme:', $this->suffix('page.phtml'));
        $this->request->failPath = false;
        self::assertStringContainsString('request_theme:frontend:7', $this->suffix('page.phtml'));
    }

    private function suffix(string $file, string $area = 'frontend'): string
    {
        $data = new DataObject(['filename' => $file, 'area' => $area]);
        $event = new Event(['data' => $data]);
        $this->observer->execute($event);
        return (string)$data->getData('suffix');
    }

    private function enterContext(string $id, ?ScopeIdentity $scope = null): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId($id);
        RequestContext::installScopeIdentity($scope ?? ScopeIdentity::website(1, 'first'));
        State::setRequestLanguageOverride('en_US');
        $this->installTheme(1, '/first/');
    }

    private function installTheme(int $id, string $path): void
    {
        $state = (new \ReflectionMethod(ThemeData::class, 'state'))->invoke(null);
        $state->currentTheme = (new CacheSuffixTheme())->setData(['id' => $id, 'path' => $path]);
        $state->currentArea = 'frontend';
        $state->initialized = true;
    }
}

final class CacheSuffixRequest extends Request
{
    public string $uri = '/products';
    public int $pathReads = 0;
    public bool $failPath = false;
    public function __construct() {}
    public function getUri(): string { return $this->uri; }
    public function getUrlPath(string $url = ''): string
    {
        $this->pathReads++;
        if ($this->failPath) { throw new \RuntimeException('Path unavailable'); }
        return (string)parse_url($this->uri, PHP_URL_PATH);
    }
    public function getParam(string $key, mixed $default = '', string $filter = '') { return $this->getData($key) ?? $default; }
    public function getGet(string $key = '', mixed $default = null) { return $key === '' ? $this->getData() : ($this->getData($key) ?? $default); }
}

final class CacheSuffixTheme extends WelineTheme
{
    public function __construct() {}
    public function getId(mixed $default = 0) { return $this->getData('id') ?? $default; }
    public function getPath(): string { return (string)$this->getData('path'); }
    public function reset(): static { return $this->setData([]); }
    public function load(string|int $field_or_pk_value, $value = null, bool $forceReload = false): \Weline\Framework\Database\AbstractModel
    {
        return $this->setData(['id' => (int)$field_or_pk_value, 'path' => '/theme-' . $field_or_pk_value . '/']);
    }
}
