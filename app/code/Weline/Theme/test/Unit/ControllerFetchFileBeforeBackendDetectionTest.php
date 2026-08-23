<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\ThemeModeResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Theme/Service/ThemeContextService.php';
require_once BP . 'app/code/Weline/Theme/Observer/ControllerFetchFileBefore.php';

final class ControllerFetchFileBeforeBackendDetectionTest extends TestCase
{
    public function testBackendControllerRouteKeepsBackendDetectionWhenContextIsDirty(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getServer')
            ->willReturnCallback(static function (string $key) {
                return match ($key) {
                    'WELINE_IS_BACKEND', 'WELINE_AREA' => '',
                    'REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/index',
                    default => '',
                };
            });
        $request->method('getRouterData')
            ->willReturnCallback(static function (string $key) {
                return match ($key) {
                    'class/controller_name' => 'Backend/AiSiteAgent',
                    'class/name' => 'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                    default => '',
                };
            });
        $request->method('isBackend')->willReturn(false);

        $observer = $this->createObserver($this->createMock(ThemeContextService::class));

        $method = new \ReflectionMethod($observer, 'isBackendRequest');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($observer, $request, null));
    }

    public function testOrdinaryBackendLayoutUsesExplicitGlobalScopeWithoutFrozenRequestIdentity(): void
    {
        RequestContext::resetWelineVars();
        self::assertNull(RequestContext::scopeIdentity());

        $themeContext = $this->createMock(ThemeContextService::class);
        $themeContext->expects(self::never())->method('resolveCurrentScope');
        $observer = $this->createObserver($themeContext);

        $method = new \ReflectionMethod($observer, 'resolveRequestLayoutScope');
        $method->setAccessible(true);

        self::assertSame(
            ThemeContextService::DEFAULT_SCOPE,
            $method->invoke($observer, ThemeContextService::AREA_BACKEND, null, true, false),
        );
    }

    public function testThemeEditorLayoutStillRejectsMissingFrozenRequestIdentity(): void
    {
        RequestContext::resetWelineVars();
        self::assertNull(RequestContext::scopeIdentity());

        $themeContext = $this->createMock(ThemeContextService::class);
        $themeContext->expects(self::once())
            ->method('resolveCurrentScope')
            ->with(ThemeContextService::AREA_BACKEND, null)
            ->willThrowException(new \RuntimeException('missing frozen scope'));
        $observer = $this->createObserver($themeContext);

        $method = new \ReflectionMethod($observer, 'resolveRequestLayoutScope');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing frozen scope');
        $method->invoke($observer, ThemeContextService::AREA_BACKEND, null, true, true);
    }

    public function testFrontendLayoutNeverUsesBackendGlobalScopeFallback(): void
    {
        RequestContext::resetWelineVars();
        self::assertNull(RequestContext::scopeIdentity());

        $themeContext = $this->createMock(ThemeContextService::class);
        $themeContext->expects(self::once())
            ->method('resolveCurrentScope')
            ->with(ThemeContextService::AREA_FRONTEND, null)
            ->willThrowException(new \RuntimeException('missing storefront scope'));
        $observer = $this->createObserver($themeContext);

        $method = new \ReflectionMethod($observer, 'resolveRequestLayoutScope');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing storefront scope');
        $method->invoke($observer, ThemeContextService::AREA_FRONTEND, null, false, false);
    }

    public function testResolvedScopeIsReusedForEveryThemeDataPreload(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/Observer/ControllerFetchFileBefore.php',
        );

        self::assertIsString($source);
        self::assertSame(2, substr_count($source, 'ThemeData::performanceLoad(scope: $scope);'));
        self::assertStringNotContainsString('ThemeData::performanceLoad();', $source);
    }

    private function createObserver(ThemeContextService $themeContext): ControllerFetchFileBefore
    {
        return new ControllerFetchFileBefore(
            $this->createMock(WelineTheme::class),
            $themeContext,
            new ThemePageTypeResolver(),
            $this->createMock(ThemeModeResolver::class),
        );
    }
}
