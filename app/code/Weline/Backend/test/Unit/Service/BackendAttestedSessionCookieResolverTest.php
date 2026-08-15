<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\BackendAttestedSessionCookieResolver;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\RequestContext;

final class BackendAttestedSessionCookieResolverTest extends TestCase
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

    public function testAttestedBackendSessionCanUseUnscopedAliasDuringWebsiteScopedApiRequest(): void
    {
        $scopedSessionId = str_repeat('a', 32);
        $backendSessionId = str_repeat('b', 32);
        $this->enterScopedRequest([
            'WELINE_SESSID_9502_w0' => $scopedSessionId,
            'WELINE_SESSID_9502' => $backendSessionId,
        ]);

        $resolver = new BackendAttestedSessionCookieResolver();

        self::assertSame($backendSessionId, $resolver->resolve(hash('sha256', $backendSessionId)));
        self::assertSame($scopedSessionId, $resolver->resolve(hash('sha256', $scopedSessionId)));
    }

    public function testUnrelatedCookieAndUnattestedSessionCannotBeSelected(): void
    {
        $this->enterScopedRequest([
            'WELINE_SESSID_9502_w0' => str_repeat('a', 32),
            'WELINE_SESSID_9502_w9' => str_repeat('b', 32),
            'unrelated' => str_repeat('c', 32),
        ]);

        $resolver = new BackendAttestedSessionCookieResolver();

        self::assertNull($resolver->resolve(hash('sha256', str_repeat('b', 32))));
        self::assertNull($resolver->resolve(hash('sha256', str_repeat('c', 32))));
        self::assertNull($resolver->resolve('not-a-fingerprint'));
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
        RequestContext::setId('backend-attested-session-cookie-test');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9502');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', 'shop.test');
        Context::current()->set('input.cookie', $cookies);
    }
}
