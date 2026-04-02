<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Observer;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Observer\VirtualThemeRequestInterceptor;
use GuoLaiRen\PageBuilder\Service\VirtualThemeContextService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

class VirtualThemeRequestInterceptorTest extends TestCase
{
    public function testExecuteWithNoVirtualThemeIdClearsStoredContextOutsidePageBuilderRoutes(): void
    {
        $request = $this->createMock(Request::class);
        $session = $this->createMock(\Weline\Framework\Session\Session::class);
        $virtualTheme = $this->createMock(VirtualTheme::class);

        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                return $key === 'virtual_theme_id' ? 0 : $default;
            });
        $request->method('getUrlPath')->willReturn('/customer/account/login');
        $request->expects($this->never())->method('setGet');

        $session->expects($this->once())
            ->method('delete')
            ->with(VirtualThemeContextService::SESSION_KEY);

        $contextService = new VirtualThemeContextService($request, $session);

        $observer = new VirtualThemeRequestInterceptor($request, $contextService, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);
    }

    public function testExecuteWithVirtualThemeIdFromUrlPersistsSelfManagedContextOnly(): void
    {
        $virtualThemeId = 123;

        $request = $this->createMock(Request::class);
        $session = $this->createMock(\Weline\Framework\Session\Session::class);
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getPath', 'getSessionId', 'load'])
            ->getMock();

        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($virtualThemeId) {
                return $key === 'virtual_theme_id' ? $virtualThemeId : $default;
            });
        $request->method('getUrlPath')->willReturn('/pagebuilder/backend/preview/full');

        $virtualTheme->method('getId')->willReturn($virtualThemeId);
        $virtualTheme->method('getName')->willReturn('Test Virtual Theme');
        $virtualTheme->method('getPath')->willReturn('ai/test-theme');
        $virtualTheme->method('getSessionId')->willReturn(456);
        $virtualTheme->method('load')->willReturnSelf();

        $persistedContext = null;
        $session->expects($this->once())
            ->method('setData')
            ->with(
                VirtualThemeContextService::SESSION_KEY,
                $this->callback(static function (array $context) use (&$persistedContext, $virtualThemeId): bool {
                    $persistedContext = $context;
                    return $context['virtual_theme_id'] === $virtualThemeId
                        && $context['theme_path'] === 'ai/test-theme';
                })
            );

        $setGetCalls = [];
        $request->method('setGet')
            ->willReturnCallback(function (string $key, mixed $value) use (&$setGetCalls, $request) {
                $setGetCalls[$key] = $value;
                return $request;
            });

        $contextService = new VirtualThemeContextService($request, $session);
        $observer = new VirtualThemeRequestInterceptor($request, $contextService, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);

        $this->assertIsArray($persistedContext);
        $this->assertSame($virtualThemeId, $persistedContext['virtual_theme_id']);
        $this->assertSame('Test Virtual Theme', $persistedContext['theme_name']);
        $this->assertSame('ai/test-theme', $persistedContext['theme_path']);
        $this->assertSame('frontend', $persistedContext['area']);
        $this->assertSame(456, $persistedContext['session_id']);

        $this->assertSame($virtualThemeId, $setGetCalls['virtual_theme_id'] ?? null);
        $this->assertSame($virtualThemeId, $setGetCalls['pagebuilder_virtual_theme_id'] ?? null);
        $this->assertSame('1', $setGetCalls['is_virtual_theme'] ?? null);
        $this->assertSame('frontend', $setGetCalls['editor_area'] ?? null);
        $this->assertSame('pagebuilder', $setGetCalls['shell'] ?? null);
        $this->assertSame('ai/test-theme', $setGetCalls['virtual_theme_path'] ?? null);
        $this->assertSame('frontend', $setGetCalls['theme_component_area'] ?? null);
        $this->assertArrayNotHasKey('preview_theme', $setGetCalls);
        $this->assertArrayNotHasKey('preview_area', $setGetCalls);
        $this->assertArrayNotHasKey('frontend_theme_id', $setGetCalls);
    }

    public function testExecuteWithVirtualThemeIdFromStoredContextOnPageBuilderRoute(): void
    {
        $virtualThemeId = 789;

        $request = $this->createMock(Request::class);
        $session = $this->createMock(\Weline\Framework\Session\Session::class);
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getPath', 'getSessionId', 'load'])
            ->getMock();

        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                return $key === 'virtual_theme_id' ? 0 : $default;
            });
        $request->method('getUrlPath')->willReturn('/pagebuilder/backend/page/virtual-edit');

        $session->method('getData')
            ->with(VirtualThemeContextService::SESSION_KEY)
            ->willReturn(['virtual_theme_id' => $virtualThemeId]);
        $session->expects($this->once())
            ->method('setData')
            ->with(
                VirtualThemeContextService::SESSION_KEY,
                $this->callback(static fn(array $context): bool => (int)($context['virtual_theme_id'] ?? 0) === $virtualThemeId)
            );

        $virtualTheme->method('getId')->willReturn($virtualThemeId);
        $virtualTheme->method('getName')->willReturn('Session Virtual Theme');
        $virtualTheme->method('getPath')->willReturn('ai/session-theme');
        $virtualTheme->method('getSessionId')->willReturn(999);
        $virtualTheme->method('load')->willReturnSelf();

        $setGetCalled = false;
        $request->method('setGet')
            ->willReturnCallback(function (string $key, mixed $value) use (&$setGetCalled, $request) {
                $setGetCalled = true;
                return $request;
            });

        $contextService = new VirtualThemeContextService($request, $session);
        $observer = new VirtualThemeRequestInterceptor($request, $contextService, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);

        $this->assertTrue($setGetCalled);
    }

    public function testExecuteWithInvalidVirtualThemeIdDoesNotInjectRequestState(): void
    {
        $request = $this->createMock(Request::class);
        $session = $this->createMock(\Weline\Framework\Session\Session::class);
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'load'])
            ->getMock();

        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null) {
                return $key === 'virtual_theme_id' ? 999 : $default;
            });
        $request->method('getUrlPath')->willReturn('/pagebuilder/backend/preview/full');
        $request->expects($this->never())->method('setGet');

        $session->expects($this->never())->method('setData');

        $virtualTheme->method('getId')->willReturn(0);
        $virtualTheme->method('load')->willReturnSelf();

        $contextService = new VirtualThemeContextService($request, $session);
        $observer = new VirtualThemeRequestInterceptor($request, $contextService, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);
    }
}
