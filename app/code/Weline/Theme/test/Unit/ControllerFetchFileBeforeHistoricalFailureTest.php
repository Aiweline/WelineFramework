<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeModeResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\LayoutEntity\SolidifiedControllerTemplateResolver;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemeVirtualLayoutService;

final class ControllerFetchFileBeforeHistoricalFailureTest extends TestCase
{
    private array $previousInstances = [];
    private object $sourceResolver;
    private object $virtualResolver;
    private ThemeContextService $context;

    protected function setUp(): void
    {
        ControllerFetchFileBefore::clearRuntimeCache();
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $default);
        $request->method('getGet')->willReturnCallback(static fn($key = '', $default = '') => $default);
        $request->method('getServer')->willReturn('');
        $request->method('getRouterData')->willReturn('');
        $request->method('getUrlPath')->willReturn('/');
        $request->method('isBackend')->willReturn(false);
        $this->replaceInstance(Request::class, $request);
        $preview = new class {
            public function isEditorThemeRequest(): bool { return false; }
            public function hasAuthoritativePreviewContext(): bool { return false; }
        };
        $this->replaceInstance(PreviewContextService::class, $preview);

        $this->sourceResolver = new class {
            public ?\Throwable $failure = null;
            public int $calls = 0;
            public function resolveExecutableLayoutPath(...$args): ?string
            {
                ++$this->calls;
                if ($this->failure !== null) { throw $this->failure; }
                return null;
            }
        };
        $this->replaceInstance(SolidifiedControllerTemplateResolver::class, $this->sourceResolver);
        $this->virtualResolver = new class {
            public int $calls = 0;
            public function resolvePublishedRuntimeLayout(...$args): ?array
            {
                ++$this->calls;
                return null;
            }
        };
        $this->replaceInstance(ThemeVirtualLayoutService::class, $this->virtualResolver);
        $this->context = $this->createMock(ThemeContextService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousInstances as $class => $previous) {
            if ($previous === null) { ObjectManager::removeInstance($class); }
            else { ObjectManager::setInstance($class, $previous); }
        }
        ControllerFetchFileBefore::clearRuntimeCache();
    }

    public function testHistoricalSourceFailureCrossesBothFallbackBoundariesUnchanged(): void
    {
        $failure = new \RuntimeException('historical_resource_snapshot_missing');
        $this->sourceResolver->failure = $failure;
        $this->context->method('resolveCurrentScope')->willReturn('history.test.default');
        $observer = $this->observer();
        $event = $this->event();

        $actual = null;
        try {
            $observer->execute($event);
        } catch (\RuntimeException $error) {
            $actual = $error;
        }
        self::assertSame($failure, $actual, 'A missing historical reference must not become a successful original page.');
        self::assertSame(1, $this->sourceResolver->calls);
        self::assertSame(0, $this->virtualResolver->calls);
        self::assertNull($event->getData('data')->getData('layoutTemplate'));
    }

    public function testOuterLayoutFallbackDoesNotHideHistoricalInputFailure(): void
    {
        $failure = new \RuntimeException('historical_intent_reference_missing');
        $scopeCalls = 0;
        $this->context->method('resolveCurrentScope')->willReturnCallback(
            static function () use (&$scopeCalls, $failure): string {
                if (++$scopeCalls === 1) { throw $failure; }
                return 'history.test.default';
            },
        );
        $observer = $this->observer();
        $event = $this->event();

        $actual = null;
        try {
            $observer->execute($event);
        } catch (\RuntimeException $error) {
            $actual = $error;
        }
        self::assertSame($failure, $actual, 'The outer compatibility fallback must rethrow missing historical input.');
        self::assertSame(1, $scopeCalls);
        self::assertSame(0, $this->sourceResolver->calls);
    }

    public function testAbsentSolidifiedSourcePreservesNormalControllerFallback(): void
    {
        $this->context->method('resolveCurrentScope')->willReturn('history.test.default');
        $observer = $this->observer();
        $event = $this->event();
        $observer->execute($event);

        self::assertSame(1, $this->sourceResolver->calls);
        self::assertSame(1, $this->virtualResolver->calls);
        self::assertSame('Weline_Theme::original-content.phtml', $event->getData('data')->getData('fileName'));
    }

    private function observer(): ControllerFetchFileBefore
    {
        $theme = $this->createMock(WelineTheme::class);
        $theme->method('getId')->willReturn(391);
        $mode = $this->createMock(ThemeModeResolver::class);
        $mode->method('resolve')->willReturn('default');
        $observer = new ControllerFetchFileBefore($theme, $this->context, new ThemePageTypeResolver(), $mode);
        $state = (new \ReflectionMethod($observer, 'requestCacheState'))->invoke(null);
        $state->themeByAreaCache['frontend|published'] = $theme;
        $state->layoutConfigCache['391_frontend_history.test.default'] = [];
        $state->colorsCache['391_frontend_history.test.default'] = [];
        $state->resolvedLayoutPathCache['theme/frontend/layouts/history-test/default.phtml|391|frontend'] = null;
        return $observer;
    }

    private function event(): Event
    {
        return new Event(['data' => new DataObject([
            'layoutType' => 'history-test.default',
            'fileName' => 'Weline_Theme::original-content.phtml',
        ])]);
    }

    private function replaceInstance(string $class, object $instance): void
    {
        $this->previousInstances[$class] = ObjectManager::_getInstance($class);
        ObjectManager::setInstance($class, $instance);
    }
}
