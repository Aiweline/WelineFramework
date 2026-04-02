<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\VirtualThemeContextService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;

class VirtualThemeContextServiceTest extends TestCase
{
    private VirtualThemeContextService $service;
    private Request $request;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(Request::class);
        $this->session = $this->createMock(Session::class);

        $this->service = new VirtualThemeContextService(
            $this->request,
            $this->session
        );
    }

    public function testGetDefaultContext(): void
    {
        $context = $this->service->getDefaultContext();

        $this->assertIsArray($context);
        $this->assertArrayHasKey('virtual_theme_id', $context);
        $this->assertArrayHasKey('ai_session_id', $context);
        $this->assertArrayHasKey('preview_token', $context);
        $this->assertSame(0, $context['virtual_theme_id']);
        $this->assertSame('', $context['ai_session_id']);
        $this->assertSame('', $context['preview_token']);
    }

    public function testGetCurrentContextWithoutSessionAndRequest(): void
    {
        $this->session->method('getData')->willReturn(null);
        $this->request->method('getParam')->willReturn(null);

        $context = $this->service->getCurrentContext();

        $this->assertSame($this->service->getDefaultContext(), $context);
    }

    public function testGetCurrentContextFromSession(): void
    {
        $sessionData = [
            'virtual_theme_id' => 123,
            'ai_session_id' => 'test-session-id',
            'preview_token' => 'test-token',
        ];

        $this->session->method('getData')
            ->with(VirtualThemeContextService::SESSION_KEY)
            ->willReturn($sessionData);

        $this->request->method('getParam')->willReturn(null);

        $context = $this->service->getCurrentContext();

        $this->assertSame(123, $context['virtual_theme_id']);
        $this->assertSame('test-session-id', $context['ai_session_id']);
        $this->assertSame('test-token', $context['preview_token']);
    }

    public function testGetCurrentContextFromRequest(): void
    {
        $this->session->method('getData')->willReturn(null);

        $this->request->method('getParam')
            ->willReturnMap([
                ['virtual_theme_id', null, 456],
                ['ai_session_id', null, 'request-session-id'],
                ['preview_token', null, 'request-token'],
            ]);

        $context = $this->service->getCurrentContext();

        $this->assertSame(456, $context['virtual_theme_id']);
        $this->assertSame('request-session-id', $context['ai_session_id']);
        $this->assertSame('request-token', $context['preview_token']);
    }

    public function testGetCurrentContextRequestOverridesSession(): void
    {
        $sessionData = [
            'virtual_theme_id' => 123,
            'ai_session_id' => 'session-id',
            'preview_token' => 'session-token',
        ];

        $this->session->method('getData')
            ->with(VirtualThemeContextService::SESSION_KEY)
            ->willReturn($sessionData);

        $this->request->method('getParam')
            ->willReturnMap([
                ['virtual_theme_id', null, 789],
                ['ai_session_id', null, 'request-id'],
                ['preview_token', null, null],
            ]);

        $context = $this->service->getCurrentContext();

        $this->assertSame(789, $context['virtual_theme_id']);
        $this->assertSame('request-id', $context['ai_session_id']);
        $this->assertSame('session-token', $context['preview_token']);
    }

    public function testGetCurrentVirtualThemeId(): void
    {
        $this->session->method('getData')->willReturn(['virtual_theme_id' => 999]);
        $this->request->method('getParam')->willReturn(null);

        $themeId = $this->service->getCurrentVirtualThemeId();

        $this->assertSame(999, $themeId);
    }

    public function testGetAiSessionId(): void
    {
        $this->session->method('getData')->willReturn(['ai_session_id' => 'my-ai-session']);
        $this->request->method('getParam')->willReturn(null);

        $sessionId = $this->service->getAiSessionId();

        $this->assertSame('my-ai-session', $sessionId);
    }

    public function testGetPreviewToken(): void
    {
        $this->session->method('getData')->willReturn(['preview_token' => 'my-preview-token']);
        $this->request->method('getParam')->willReturn(null);

        $token = $this->service->getPreviewToken();

        $this->assertSame('my-preview-token', $token);
    }

    public function testIsVirtualThemeRequestReturnsTrueWhenThemeIdSet(): void
    {
        $this->session->method('getData')->willReturn(['virtual_theme_id' => 100]);
        $this->request->method('getParam')->willReturn(null);

        $this->assertTrue($this->service->isVirtualThemeRequest());
    }

    public function testIsVirtualThemeRequestReturnsFalseWhenThemeIdNotSet(): void
    {
        $this->session->method('getData')->willReturn(null);
        $this->request->method('getParam')->willReturn(null);

        $this->assertFalse($this->service->isVirtualThemeRequest());
    }

    public function testPersistContext(): void
    {
        $context = [
            'virtual_theme_id' => 555,
            'ai_session_id' => 'persist-session',
            'preview_token' => 'persist-token',
        ];

        $this->session->expects($this->once())
            ->method('setData')
            ->with(VirtualThemeContextService::SESSION_KEY, $context);

        $this->request->expects($this->exactly(3))
            ->method('setGet');

        $result = $this->service->persistContext($context);

        $this->assertSame($context, $result);
    }

    public function testPersistContextWithoutSyncRequest(): void
    {
        $context = [
            'virtual_theme_id' => 666,
            'ai_session_id' => 'no-sync',
            'preview_token' => 'no-sync-token',
        ];

        $this->session->expects($this->once())
            ->method('setData')
            ->with(VirtualThemeContextService::SESSION_KEY, $context);

        $this->request->expects($this->never())
            ->method('setGet');

        $result = $this->service->persistContext($context, false);

        $this->assertSame($context, $result);
    }

    public function testClearContext(): void
    {
        $this->session->expects($this->once())
            ->method('delete')
            ->with(VirtualThemeContextService::SESSION_KEY);

        $this->service->clearContext();
    }

    public function testNormalizeContextWithNegativeThemeId(): void
    {
        $context = ['virtual_theme_id' => -10];

        $normalized = $this->service->normalizeContext($context);

        $this->assertSame(0, $normalized['virtual_theme_id']);
    }

    public function testNormalizeContextWithNonScalarValues(): void
    {
        $context = [
            'virtual_theme_id' => '123',
            'ai_session_id' => ['invalid'],
            'preview_token' => null,
        ];

        $normalized = $this->service->normalizeContext($context);

        $this->assertSame(123, $normalized['virtual_theme_id']);
        $this->assertSame('', $normalized['ai_session_id']);
        $this->assertSame('', $normalized['preview_token']);
    }

    public function testNormalizeContextTrimsStrings(): void
    {
        $context = [
            'ai_session_id' => '  trimmed-session  ',
            'preview_token' => '  trimmed-token  ',
        ];

        $normalized = $this->service->normalizeContext($context);

        $this->assertSame('trimmed-session', $normalized['ai_session_id']);
        $this->assertSame('trimmed-token', $normalized['preview_token']);
    }

    public function testBuildContextMergesCurrent(): void
    {
        $this->session->method('getData')->willReturn(['virtual_theme_id' => 111]);
        $this->request->method('getParam')->willReturn(null);

        $result = $this->service->buildContext(['ai_session_id' => 'new-session']);

        $this->assertSame(111, $result['virtual_theme_id']);
        $this->assertSame('new-session', $result['ai_session_id']);
    }

    public function testBuildContextWithoutMergingCurrent(): void
    {
        $this->session->method('getData')->willReturn(['virtual_theme_id' => 222]);
        $this->request->method('getParam')->willReturn(null);

        $result = $this->service->buildContext(['ai_session_id' => 'new-session'], false);

        $this->assertSame(0, $result['virtual_theme_id']);
        $this->assertSame('new-session', $result['ai_session_id']);
    }

    public function testPersistCurrentRequestContext(): void
    {
        $this->session->method('getData')->willReturn(['virtual_theme_id' => 333]);
        $this->request->method('getParam')->willReturn(null);

        $this->session->expects($this->once())
            ->method('setData')
            ->with(
                VirtualThemeContextService::SESSION_KEY,
                $this->callback(function ($context) {
                    return $context['virtual_theme_id'] === 333
                        && $context['ai_session_id'] === 'override-session';
                })
            );

        $result = $this->service->persistCurrentRequestContext(['ai_session_id' => 'override-session']);

        $this->assertSame(333, $result['virtual_theme_id']);
        $this->assertSame('override-session', $result['ai_session_id']);
    }
}
