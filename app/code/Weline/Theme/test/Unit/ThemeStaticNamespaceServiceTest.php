<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\Framework\View\PublicThemeNamespace;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeResourceGateway;
use Weline\Theme\Service\ThemeStaticNamespaceService;

class ThemeStaticNamespaceServiceTest extends TestCase
{
    public function testPreviewTokenNamespaceUsesDedicatedPreviewPrefix(): void
    {
        $service = $this->createService(true, [
            'frontend_theme_id' => 11,
            'preview_token' => 'pv_test_123',
            'editor_area' => 'frontend',
            'shell' => 'preview',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'default',
        ]);

        $theme = $this->createTheme('WeShop/motor');

        $this->assertSame(
            '__preview/token_pv_test_123/WeShop/motor',
            $service->resolvePublicThemePath($theme)
        );
    }

    public function testPreviewContextQueryIncludesCanonicalPreviewFields(): void
    {
        $service = $this->createService(true, [
            'frontend_theme_id' => 15,
            'backend_theme_id' => 0,
            'preview_token' => 'pv_query_456',
            'editor_area' => 'frontend',
            'shell' => 'preview',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'homepage/default',
            'version_id' => 91,
        ]);

        $url = $service->appendPreviewContextQuery('/static/__preview/token_pv_query_456/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/theme.css');

        $this->assertStringContainsString('frontend_theme_id=15', $url);
        $this->assertStringContainsString('shell=', $url);
        $this->assertStringContainsString('version_id=91', $url);
        $this->assertStringContainsString('weline_preview_token=pv_query_456', $url);
    }

    public function testLiveRequestsKeepOriginalThemePathWithoutPreviewNamespace(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $this->assertSame(
            'WeShop/motor',
            $service->resolvePublicThemePath($this->createTheme('WeShop/motor'))
        );
        $this->assertSame(
            '/static/WeShop/motor/theme.css',
            $service->appendPreviewContextQuery('/static/WeShop/motor/theme.css')
        );
    }

    public function testLivePageQueryIgnoresStalePreviewRequestBag(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn('/weline_dashboard/backend/dashboard/index');
        $request->method('getServer')->willReturnCallback(
            static fn(string $key, mixed $default = null): mixed => $key === 'REQUEST_URI'
                ? '/admin/weline_dashboard/backend/dashboard/index?website_id=1&view_id=1'
                : $default
        );
        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return [
                    'backend_theme_id' => 7,
                    'editor_area' => 'backend',
                    'shell' => 'theme-editor',
                    'preview_mode' => 'live',
                    'status' => 'draft',
                    'scope' => 'dashboard_view:1',
                ][$key] ?? $default;
            }
        );
        $request->method('getHeader')->willReturn(null);
        $request->method('setGet')->willReturnSelf();

        $session = $this->createMock(Session::class);
        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentPreviewData')->willReturn(null);
        $previewTokenService->method('getTokenFromRequest')->willReturn(null);

        $previewContextService = new PreviewContextService(
            $request,
            $session,
            $previewTokenService,
            $this->createMock(WelineTheme::class),
            new PreviewRequestInspector($request),
        );
        $service = new ThemeStaticNamespaceService($previewContextService, $session);

        $this->assertSame(
            'WeShop/motor',
            $service->resolvePublicThemePath($this->createTheme('WeShop/motor'))
        );
        $this->assertSame(
            '/static/WeShop/motor/theme.css',
            $service->appendPreviewContextQuery('/static/WeShop/motor/theme.css')
        );
    }

    public function testDesignAbsoluteOriginPathResolvesToPublicThemePath(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $themePath = rtrim(str_replace('\\', '/', BP), '/') . '/app/design/WeShop/motor';

        $this->assertSame(
            'WeShop/motor',
            $service->resolvePublicThemePath($this->createTheme($themePath))
        );
    }

    public function testModuleNotationOriginPathResolvesToPublicThemePath(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $this->assertSame(
            'Weline/Theme/view/theme',
            $service->resolvePublicThemePath($this->createTheme('Weline_Theme::view/theme'))
        );
    }

    public function testModuleThemeAbsoluteOriginPathStaysRelativeInPreviewStaticPath(): void
    {
        $service = $this->createService(true, [
            'backend_theme_id' => 10,
            'preview_token' => 'pv_absolute_module',
            'editor_area' => 'backend',
            'shell' => 'theme-editor',
            'preview_mode' => 'live',
            'status' => 'draft',
            'scope' => 'default',
        ]);
        $gateway = new ThemeResourceGateway(
            $this->createMock(ThemeDirectoryResolver::class),
            new ThemeContextService($this->createMock(WelineTheme::class)),
            $service,
            $this->createMock(Request::class),
        );
        $theme = $this->createTheme(
            rtrim(str_replace('\\', '/', BP), '/') . '/app/code/Weline/Theme/view/theme',
            10
        );

        $path = str_replace('\\', '/', $gateway->buildLayoutAssetDiskPath('backend', 'default', 'default', 'css', $theme));
        $staticRelative = substr($path, strpos($path, '/pub/static/') + strlen('/pub/static/'));

        $this->assertSame(
            '__preview/token_pv_absolute_module/Weline/Theme/view/theme/backend/layouts/default/default.css',
            $staticRelative
        );
    }

    /**
     * 安全契约：无论 `theme.path` 原值多畸形，`resolvePublicThemePath()` 都不得产出
     * 可越出 `pub/static` 的命名空间（`..` / `.` / 空段 / `::`）。
     *
     * 历史实现（本服务自实现的 `normalizePublicThemePath()`）会把 `..`、`a//b`、`a::b`
     * 等输入**原样返回**，于是 `theme:upgrade` 会把文件铺到 `pub/static` 之外或长出畸形
     * 目录树（正是本次清理掉的那类污染）。现在委托给唯一权威
     * {@see PublicThemeNamespace::tryResolve()}。
     *
     * @dataProvider hostileOriginPathProvider
     */
    public function testUnsafeOriginPathNeverProducesTraversableNamespace(string $originPath): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $namespace = $service->resolvePublicThemePath($this->createTheme($originPath));

        // 允许为空（主题原值与配置项都无法解析时的既定回退），但绝不能是畸形/可穿越的命名空间。
        if ($namespace === '') {
            self::assertSame('', $namespace);
            return;
        }

        self::assertTrue(
            PublicThemeNamespace::isSafeRelativeNamespace($namespace),
            \sprintf('畸形 theme.path `%s` 产出了不安全命名空间 `%s`', $originPath, $namespace)
        );
        self::assertStringNotContainsString('..', $namespace);
        self::assertStringNotContainsString('::', $namespace);
        self::assertFalse(\str_starts_with($namespace, '/'));
    }

    /**
     * 与唯一权威逐输入一致：本服务只是 `PublicThemeNamespace` 的薄封装。
     *
     * 这是「语义分歧」回归的哨兵——两处实现一旦再次分叉就会在此失败。仅对**可解析**的
     * 输入成立（无法解析时本服务会按既定行为回退配置项，而 `tryResolve()` 返回 `null`）。
     */
    public function testResolutionDelegatesToSingleAuthority(): void
    {
        $service = $this->createService(false, [
            'frontend_theme_id' => 0,
            'preview_token' => '',
        ]);

        $resolvablePaths = [
            'Weline/hanfu',
            'Weline/hanfu/',
            'WeShop/motor',
            'Weline_Theme::view/theme',
            'Weline_Frontend::view/theme',
            'Weline_Hanfu::view\\theme',
        ];

        // 项目内绝对路径形态依赖运行时常量；未定义时该输入本就「无法解析」，不纳入等价断言。
        if (defined('APP_CODE_PATH')) {
            $resolvablePaths[] = \rtrim(\str_replace('\\', '/', (string)APP_CODE_PATH), '/')
                . '/Weline/Theme/view/theme';
            $resolvablePaths[] = 'app/code/Weline/Theme/view/theme';
        }

        $designRoot = \rtrim(\str_replace('\\', '/', (string)Env::path_THEME_DESIGN_DIR), '/');
        if ($designRoot !== '') {
            $resolvablePaths[] = $designRoot . '/WeShop/motor';
            $resolvablePaths[] = 'app/design/Weline/hanfu';
        }

        foreach ($resolvablePaths as $originPath) {
            $expected = PublicThemeNamespace::tryResolve($originPath);
            self::assertNotNull($expected, '用例前提：该输入应可解析 → ' . $originPath);
            self::assertSame(
                $expected,
                $service->resolvePublicThemePath($this->createTheme($originPath)),
                'ThemeStaticNamespaceService 应与 PublicThemeNamespace::tryResolve 逐输入一致：' . $originPath
            );
        }
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function hostileOriginPathProvider(): array
    {
        return [
            'traversal' => ['Weline/hanfu/../evil'],
            'deep traversal' => ['../../etc'],
            'absolute traversal' => ['/Users/example/../../etc'],
            'dot only' => ['.'],
            'dotdot only' => ['..'],
            'double slash' => ['Weline//hanfu'],
            'bare module separator' => ['a::b'],
            'module separator without inner' => ['Weline_Theme::'],
            'absolute source outside project' => ['/Users/example/project/app/code/Weline/Theme/view/theme'],
            'windows drive' => ['C:/project/app/code/Weline/Theme/view/theme'],
            'relative code traversal' => ['app/code/../../etc'],
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createService(bool $shouldUseStoredContext, array $context): ThemeStaticNamespaceService
    {
        $request = $this->createMock(Request::class);
        $request->method('getUrlPath')->willReturn(
            $shouldUseStoredContext ? '/theme/frontend/theme-preview/gateway' : '/'
        );
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, mixed $default = null): mixed => $default
        );
        $request->method('getHeader')->willReturn(null);
        $request->method('setGet')->willReturnSelf();

        $session = $this->createMock(Session::class);
        $session->method('getData')->willReturnCallback(
            static function (string $key) use ($context): mixed {
                if ($key === PreviewContextService::SESSION_KEY) {
                    return $context;
                }

                return null;
            }
        );
        $session->method('getId')->willReturn('test-session-id');

        $previewTokenService = $this->createMock(PreviewTokenService::class);
        $previewTokenService->method('getCurrentPreviewData')->willReturn(null);
        $previewTokenService->method('getTokenFromRequest')->willReturn(null);

        $previewContextService = new PreviewContextService(
            $request,
            $session,
            $previewTokenService,
            $this->createMock(WelineTheme::class),
            new PreviewRequestInspector($request),
        );

        return new ThemeStaticNamespaceService($previewContextService, $session);
    }

    private function createTheme(string $originPath, int $id = 1): WelineTheme
    {
        $theme = $this->createMock(WelineTheme::class);
        $theme->method('getOriginPath')->willReturn($originPath);
        $theme->method('getId')->willReturn($id);

        return $theme;
    }
}
