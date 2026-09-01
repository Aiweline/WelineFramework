<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Resumable\ResumableTaskAccessDeniedException;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Runtime\ResumableTaskOwnerResolver;
use Weline\Framework\Session\AttestedSessionCookieResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

final class ResumableTaskOwnerResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CookieScope::setPolicyResolverOverride(null);
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testWorkerBackendOwnerRestoresAttestedSessionAcrossWebsiteScopedCookies(): void
    {
        $backendSessionId = str_repeat('b', 32);
        $scopedSessionId = str_repeat('a', 32);
        $this->enterScopedRequest([
            'WELINE_SESSID_9555_w0' => $scopedSessionId,
            'WELINE_SESSID_9555' => $backendSessionId,
        ]);

        $binding = new FrontendWorkerBackendBinding(
            backendUserId: 1,
            sessionFingerprint: \hash('sha256', $backendSessionId),
            authorityHost: '127.0.0.1:9555',
            issuedAt: \time() - 10,
            expiresAt: \time() + 3600,
        );
        RequestContext::set(
            FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            FrontendWorkerExecutionContext::backend($binding),
        );

        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->method('get')->willReturnCallback(static function (string $key): int {
            return $key === 'backend_acl_role_id' ? 1 : 0;
        });

        $backendSession = $this->createMock(AuthenticatedSessionInterface::class);
        $backendSession->method('isLoggedIn')->willReturn(true);
        $backendSession->method('getUserId')->willReturn(1);
        $backendSession->method('isStarted')->willReturn(true);
        $backendSession->method('getId')->willReturn($backendSessionId);
        $backendSession->method('getSession')->willReturn($rawSession);

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::once())
            ->method('restoreAuthenticatedSession')
            ->with('backend', $backendSessionId)
            ->willReturn($backendSession);
        $sessionFactory->expects(self::never())->method('createBackendSession');

        $request = $this->createMock(Request::class);
        $request->method('getServer')->with('WELINE_WEBSITE_ID')->willReturn('0');

        $owner = (new ResumableTaskOwnerResolver(
            $sessionFactory,
            $request,
            new AttestedSessionCookieResolver(),
        ))->resolve();

        self::assertSame('backend', $owner->area);
        self::assertSame('backend:1', $owner->principal);
        self::assertSame($backendSessionId, $owner->sessionId);
    }

    public function testWorkerBackendOwnerRejectsMissingAttestedSessionCookie(): void
    {
        $this->enterScopedRequest([
            'WELINE_SESSID_9555_w0' => str_repeat('a', 32),
        ]);

        $binding = new FrontendWorkerBackendBinding(
            backendUserId: 1,
            sessionFingerprint: \hash('sha256', str_repeat('b', 32)),
            authorityHost: '127.0.0.1:9555',
            issuedAt: \time() - 10,
            expiresAt: \time() + 3600,
        );
        RequestContext::set(
            FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            FrontendWorkerExecutionContext::backend($binding),
        );

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::never())->method('restoreAuthenticatedSession');

        $this->expectException(ResumableTaskAccessDeniedException::class);
        $this->expectExceptionMessage('Runtime task backend authority no longer matches the Session.');

        (new ResumableTaskOwnerResolver(
            $sessionFactory,
            $this->createMock(Request::class),
            new AttestedSessionCookieResolver(),
        ))->resolve();
    }

    /** @param array<string, string> $cookies */
    private function enterScopedRequest(array $cookies): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'test',
        ]);
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('resumable-task-owner-resolver-test');
        Context::current()->set('input.server.HTTP_HOST', '127.0.0.1:9555');
        Context::current()->set('input.server.SERVER_PORT', 9555);
        Context::current()->set('input.host', '127.0.0.1');
        Context::current()->set('input.cookie', $cookies);
    }
}
