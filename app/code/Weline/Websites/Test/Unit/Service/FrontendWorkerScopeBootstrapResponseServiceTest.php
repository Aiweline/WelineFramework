<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;
use Weline\Websites\Service\FrontendWorkerScopeBootstrapResponseService;

final class FrontendWorkerScopeBootstrapResponseServiceTest extends TestCase
{
    public const TOKEN = 'scope-token-secret-value-that-must-not-enter-html';

    private ?Context $previousContext = null;
    private ScopeIdentity $scope;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::leave();
        Context::enter(new Context([
            'meta' => ['type' => 'request'],
            'input' => [
                'method' => 'GET',
                'scheme' => 'https',
                'host' => 'shop.example.test',
                'uri' => '/',
                'full_request_uri' => 'https://shop.example.test/',
                'server' => [
                    'HTTP_HOST' => 'shop.example.test',
                    'WELINE_FULL_REQUEST_URI' => 'https://shop.example.test/',
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_SCHEME' => 'https',
                    'REQUEST_URI' => '/',
                ],
            ],
            'route' => [
                'area' => RequestContext::AREA_FRONTEND,
                'is_static' => false,
                'is_media' => false,
            ],
        ]));
        RequestContext::setId('scope-bootstrap-response-test');
        RequestContext::setWelineStoreId(7);
        RequestContext::setWelineChannelId(9);
        $this->scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);
        RequestContext::installScopeIdentity($this->scope);
        TestBootstrapScopeProvider::reset();
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->previousContext instanceof Context) {
            Context::enter($this->previousContext);
        }
        TestBootstrapScopeProvider::reset();
    }

    public function testAuthoritativeHtmlGetsOpaqueMetaAndHostOnlyHttpOnlyCookie(): void
    {
        $now = time();
        TestBootstrapScopeProvider::$binding = new FrontendWorkerScopeBinding(
            $this->scope,
            'shop.example.test',
            hash('sha256', self::TOKEN),
            $now,
            $now + 1800,
            true,
        );
        $store = new BootstrapMemoryStateStore();
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(),
            new FrontendWorkerSessionService($store),
        );
        $html = '<!doctype html><html><head><title>Store</title></head><body>Ready</body></html>';

        $decorated = $service->decorate(Response::html($html));

        self::assertInstanceOf(Response::class, $decorated);
        self::assertStringContainsString('name="weline-worker-scope-bootstrap"', $decorated->getBody());
        self::assertMatchesRegularExpression(
            '/name="weline-worker-scope-bootstrap" content="[A-Za-z0-9_-]{43}"/',
            $decorated->getBody(),
        );
        self::assertStringNotContainsString(self::TOKEN, $decorated->getBody());
        self::assertSame('private, no-store, max-age=0, must-revalidate', $decorated->getHeader('Cache-Control'));

        $cookies = array_values($decorated->getCookies());
        self::assertCount(1, $cookies);
        $cookie = $cookies[0];
        self::assertMatchesRegularExpression(
            '/^__Host-Weline-Worker-Scope-Bootstrap-[A-Za-z0-9_-]{43}$/D',
            $cookie['name'],
        );
        self::assertSame(self::TOKEN, $cookie['value']);
        self::assertSame('/', $cookie['path']);
        self::assertSame('', $cookie['domain']);
        self::assertTrue($cookie['secure']);
        self::assertTrue($cookie['httpOnly']);
        self::assertSame('Lax', $cookie['sameSite']);
        self::assertGreaterThan($now, $cookie['expire']);
        self::assertLessThanOrEqual($now + 120, $cookie['expire']);

        $requestState = json_encode(RequestContext::all(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $requestState);
        self::assertSame(1, TestBootstrapScopeProvider::$issueCalls);
        self::assertGreaterThanOrEqual(1, $store->transactions);
    }

    public function testOffModeHasZeroTokenCookieHeaderAndStoreSideEffects(): void
    {
        TestBootstrapScopeProvider::$mode = FrontendWorkerScopeRolloutDecision::MODE_OFF;
        $store = new BootstrapMemoryStateStore();
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(),
            new FrontendWorkerSessionService($store),
        );
        $response = Response::html('<html><head></head><body>Public</body></html>');

        $result = $service->decorate($response);

        self::assertSame($response, $result);
        self::assertSame('<html><head></head><body>Public</body></html>', $response->getBody());
        self::assertSame([], $response->getCookies());
        self::assertNull($response->getHeader('Cache-Control'));
        self::assertSame(0, TestBootstrapScopeProvider::$issueCalls);
        self::assertSame(0, $store->transactions);
    }
}

final class BootstrapMemoryStateStore implements FrontendWorkerStateStoreInterface
{
    /** @var array<string, mixed> */
    public array $state = [];
    public int $transactions = 0;

    public function transaction(callable $callback): mixed
    {
        ++$this->transactions;
        return $callback($this->state);
    }

    public function driver(): string
    {
        return 'test-memory';
    }

    public function isShared(): bool
    {
        return false;
    }
}

final class TestBootstrapScopeProvider implements FrontendWorkerScopeProviderInterface
{
    public static string $mode = FrontendWorkerScopeRolloutDecision::MODE_ON;
    public static ?FrontendWorkerScopeBinding $binding = null;
    public static int $issueCalls = 0;

    public static function reset(): void
    {
        self::$mode = FrontendWorkerScopeRolloutDecision::MODE_ON;
        self::$binding = null;
        self::$issueCalls = 0;
    }

    public function requiresBinding(string $requestScheme): bool
    {
        return in_array(self::$mode, [
            FrontendWorkerScopeRolloutDecision::MODE_ALLOWLIST,
            FrontendWorkerScopeRolloutDecision::MODE_ON,
        ], true);
    }

    public function rollout(ScopeIdentity $scope, string $requestScheme): FrontendWorkerScopeRolloutDecision
    {
        $enabled = self::$mode !== FrontendWorkerScopeRolloutDecision::MODE_OFF
            && self::$mode !== FrontendWorkerScopeRolloutDecision::MODE_SHADOW;
        return new FrontendWorkerScopeRolloutDecision(
            self::$mode,
            $enabled,
            $enabled,
            0,
            7,
            9,
            0,
            $enabled ? 'test_authoritative' : 'test_inactive',
        );
    }

    public function issueToken(
        ScopeIdentity $trustedScope,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?string {
        ++self::$issueCalls;
        return FrontendWorkerScopeBootstrapResponseServiceTest::TOKEN;
    }

    public function verifyToken(
        string $token,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerScopeBinding {
        return self::$binding;
    }

    public function restoreBinding(
        ?FrontendWorkerScopeBinding $binding,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?ScopeIdentity {
        return $binding?->scope;
    }
}
