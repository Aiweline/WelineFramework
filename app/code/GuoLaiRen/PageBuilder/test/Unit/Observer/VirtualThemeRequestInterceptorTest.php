<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Observer;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Observer\VirtualThemeRequestInterceptor;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class VirtualThemeRequestInterceptorTest extends TestCase
{
    public function testExecuteWithNoVirtualThemeId(): void
    {
        $request = $this->createMock(Request::class);
        $session = $this->createMock(Session::class);
        $virtualTheme = $this->createMock(VirtualTheme::class);

        $request->method('getParam')
            ->with('virtual_theme_id', 0)
            ->willReturn(0);

        $session->method('getData')
            ->with(VirtualThemeRequestInterceptor::SESSION_KEY_VIRTUAL_THEME_CONTEXT)
            ->willReturn(null);

        // 不应该调用任何注入方法
        $request->expects($this->never())->method('setGet');

        $observer = new VirtualThemeRequestInterceptor($request, $session, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);
    }

    public function testExecuteWithVirtualThemeIdFromUrl(): void
    {
        $virtualThemeId = 123;

        $request = $this->createMock(Request::class);
        $session = $this->createMock(Session::class);

        // Mock Request: 返回虚拟主题 ID
        $request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) use ($virtualThemeId) {
                return $key === 'virtual_theme_id' ? $virtualThemeId : $default;
            });

        // Mock VirtualTheme: 创建一个返回有效数据的 mock
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getPath', 'getSessionId', 'load'])
            ->getMock();

        // 配置 clone 后的行为
        $virtualTheme->method('getId')->willReturn($virtualThemeId);
        $virtualTheme->method('getName')->willReturn('Test Virtual Theme');
        $virtualTheme->method('getPath')->willReturn('ai/test-theme');
        $virtualTheme->method('getSessionId')->willReturn(456);
        $virtualTheme->method('load')->willReturnSelf();

        // 验证注入到 Request（setGet 需要返回 Request 对象）
        $setGetCalls = [];
        $request->method('setGet')
            ->willReturnCallback(function ($key, $value) use (&$setGetCalls, $request) {
                $setGetCalls[$key] = $value;
                return $request;
            });

        // 验证持久化到 Session
        $sessionData = null;
        $session->method('setData')
            ->willReturnCallback(function ($key, $value) use (&$sessionData, $session) {
                if ($key === VirtualThemeRequestInterceptor::SESSION_KEY_VIRTUAL_THEME_CONTEXT) {
                    $sessionData = $value;
                }
                return $session;
            });

        $observer = new VirtualThemeRequestInterceptor($request, $session, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);

        // 验证注入的参数
        $this->assertSame($virtualThemeId, $setGetCalls['virtual_theme_id'] ?? null);
        $this->assertSame('1', $setGetCalls['is_virtual_theme'] ?? null);
        $this->assertSame($virtualThemeId, $setGetCalls['preview_theme'] ?? null);
        $this->assertSame('frontend', $setGetCalls['preview_area'] ?? null);
        $this->assertSame($virtualThemeId, $setGetCalls['frontend_theme_id'] ?? null);
        $this->assertSame('frontend', $setGetCalls['editor_area'] ?? null);
        $this->assertSame('pagebuilder', $setGetCalls['shell'] ?? null);
        $this->assertSame('ai/test-theme', $setGetCalls['virtual_theme_path'] ?? null);

        // 验证 Session 数据
        $this->assertIsArray($sessionData);
        $this->assertSame($virtualThemeId, $sessionData['virtual_theme_id']);
        $this->assertSame('Test Virtual Theme', $sessionData['theme_name']);
        $this->assertSame('ai/test-theme', $sessionData['theme_path']);
        $this->assertSame('frontend', $sessionData['area']);
        $this->assertSame(456, $sessionData['session_id']);
    }

    public function testExecuteWithVirtualThemeIdFromSession(): void
    {
        $virtualThemeId = 789;

        $request = $this->createMock(Request::class);
        $session = $this->createMock(Session::class);

        // Mock Request: URL 中没有 virtual_theme_id
        $request->method('getParam')
            ->with('virtual_theme_id', 0)
            ->willReturn(0);

        // Mock Session: 返回虚拟主题上下文
        $session->method('getData')
            ->with(VirtualThemeRequestInterceptor::SESSION_KEY_VIRTUAL_THEME_CONTEXT)
            ->willReturn([
                'virtual_theme_id' => $virtualThemeId,
                'theme_name' => 'Session Virtual Theme',
                'theme_path' => 'ai/session-theme',
                'area' => 'frontend',
                'session_id' => 999,
            ]);

        // Mock VirtualTheme
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getPath', 'getSessionId', 'load'])
            ->getMock();

        $virtualTheme->method('getId')->willReturn($virtualThemeId);
        $virtualTheme->method('getName')->willReturn('Session Virtual Theme');
        $virtualTheme->method('getPath')->willReturn('ai/session-theme');
        $virtualTheme->method('getSessionId')->willReturn(999);
        $virtualTheme->method('load')->willReturnSelf();

        // 验证注入到 Request（至少调用一次，setGet 需要返回 Request 对象）
        $setGetCalled = false;
        $request->method('setGet')
            ->willReturnCallback(function () use (&$setGetCalled, $request) {
                $setGetCalled = true;
                return $request;
            });

        $observer = new VirtualThemeRequestInterceptor($request, $session, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);

        $this->assertTrue($setGetCalled, 'setGet should be called at least once');
    }

    public function testExecuteWithInvalidVirtualThemeId(): void
    {
        $virtualThemeId = 999;

        $request = $this->createMock(Request::class);
        $session = $this->createMock(Session::class);

        // Mock Request: 返回虚拟主题 ID
        $request->method('getParam')
            ->with('virtual_theme_id', 0)
            ->willReturn($virtualThemeId);

        // Mock VirtualTheme: 返回无效主题（ID 为 0）
        $virtualTheme = $this->getMockBuilder(VirtualTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'load'])
            ->getMock();

        $virtualTheme->method('getId')->willReturn(0); // 无效主题
        $virtualTheme->method('load')->willReturnSelf();

        // 不应该调用任何注入方法
        $request->expects($this->never())->method('setGet');
        $session->expects($this->never())->method('setData');

        $observer = new VirtualThemeRequestInterceptor($request, $session, $virtualTheme);
        $event = $this->createMock(Event::class);
        $observer->execute($event);
    }
}
