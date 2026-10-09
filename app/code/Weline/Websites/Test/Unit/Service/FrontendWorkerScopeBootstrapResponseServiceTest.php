<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestExitException;
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
        self::assertGreaterThanOrEqual(1, \count($cookies));
        $cookie = null;
        foreach ($cookies as $candidate) {
            if (\preg_match('/^__Host-Weline-Worker-Scope-Bootstrap-[A-Za-z0-9_-]{43}$/D', (string)($candidate['name'] ?? '')) === 1
                && (string)($candidate['value'] ?? '') === self::TOKEN
            ) {
                $cookie = $candidate;
                break;
            }
        }
        self::assertNotNull($cookie);
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

    public function testDecorateExpiresStaleSiblingScopeBootstrapCookies(): void
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
        $staleName = FrontendWorkerSessionService::SCOPE_BOOTSTRAP_COOKIE_PREFIX
            . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $_SERVER['HTTP_COOKIE'] = $staleName . '=stale-token; other=1';
        try {
            $service = new FrontendWorkerScopeBootstrapResponseService(
                new TestBootstrapScopeProvider(),
                new FrontendWorkerSessionService(new BootstrapMemoryStateStore()),
            );
            $decorated = $service->decorate(
                Response::html('<!doctype html><html><head><title>Store</title></head><body>Ready</body></html>')
            );
            self::assertInstanceOf(Response::class, $decorated);
            $cookies = array_values($decorated->getCookies());
            $expired = null;
            $fresh = null;
            foreach ($cookies as $cookie) {
                $name = (string)($cookie['name'] ?? '');
                if ($name === $staleName) {
                    $expired = $cookie;
                }
                if (($cookie['value'] ?? '') === self::TOKEN) {
                    $fresh = $cookie;
                }
            }
            self::assertNotNull($expired);
            self::assertSame('', (string)($expired['value'] ?? ''));
            self::assertLessThanOrEqual(1, (int)($expired['expire'] ?? 0));
            self::assertNotNull($fresh);
        } finally {
            unset($_SERVER['HTTP_COOKIE']);
        }
    }

    public function testInternalStorefrontWarmupKeepsAnonymousHtmlCookieFree(): void
    {
        $previousFlag = $_SERVER['WLS_INTERNAL_WARMUP'] ?? null;
        $_SERVER['WLS_INTERNAL_WARMUP'] = '1';
        try {
            $now = time();
            TestBootstrapScopeProvider::$binding = new FrontendWorkerScopeBinding(
                $this->scope,
                'shop.example.test',
                hash('sha256', self::TOKEN),
                $now,
                $now + 1800,
                true,
            );
            $service = new FrontendWorkerScopeBootstrapResponseService(
                new TestBootstrapScopeProvider(),
                new FrontendWorkerSessionService(new BootstrapMemoryStateStore()),
            );
            $response = Response::html('<html><body>Warmup</body></html>');

            self::assertSame($response, $service->decorate($response));
            self::assertStringNotContainsString('weline-worker-scope-bootstrap', $response->getBody());
            self::assertSame([], $response->getCookies());
        } finally {
            if ($previousFlag === null) {
                unset($_SERVER['WLS_INTERNAL_WARMUP']);
            } else {
                $_SERVER['WLS_INTERNAL_WARMUP'] = $previousFlag;
            }
        }
    }

    public function testRequestCancellationIsNotTurnedIntoMaintenanceResponse(): void
    {
        TestBootstrapScopeProvider::$issueException = new RequestExitException();
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(),
            new FrontendWorkerSessionService(new BootstrapMemoryStateStore()),
        );

        $this->expectException(RequestExitException::class);
        $service->decorate(Response::html('<!doctype html><html><head><title>Home</title></head><body>Home</body></html>'));
    }

    public function testStorefrontNotFoundHtmlGetsBootstrapWithoutChangingItsStatus(): void
    {
        $now = time();
        TestBootstrapScopeProvider::$binding = new FrontendWorkerScopeBinding(
            $this->scope, 'shop.example.test', hash('sha256', self::TOKEN),
            $now, $now + 1800, true,
        );
        $store = new BootstrapMemoryStateStore();
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(), new FrontendWorkerSessionService($store),
        );
        $response = Response::html('<html><head></head><body>Not found</body></html>', 404);

        $decorated = $service->decorate($response);

        self::assertSame(404, $decorated->getStatusCode());
        self::assertStringContainsString('Not found', $decorated->getBody());
        self::assertMatchesRegularExpression(
            '/name="weline-worker-scope-bootstrap" content="[A-Za-z0-9_-]{43}"/',
            $decorated->getBody(),
        );
        self::assertStringNotContainsString(self::TOKEN, $decorated->getBody());
        self::assertCount(1, $decorated->getCookies());
        self::assertSame(1, TestBootstrapScopeProvider::$issueCalls);
    }

    public function testPersistentFinalResponseDecorates404OnlyOnce(): void
    {
        $now = time();
        TestBootstrapScopeProvider::$binding = new FrontendWorkerScopeBinding(
            $this->scope, 'shop.example.test', hash('sha256', self::TOKEN),
            $now, $now + 1800, true,
        );
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(),
            new FrontendWorkerSessionService(new BootstrapMemoryStateStore()),
        );
        $observer = new \Weline\Websites\Observer\FrontendWorkerScopeBootstrapResponse($service);
        $event = new \Weline\Framework\Event\Event(
            'Weline_Framework_Http::response_ready',
            ['response' => Response::html('<html><head></head><body>Not found</body></html>', 404)],
        );
        $runtimeMode = new \ReflectionProperty(\Weline\Framework\Runtime\Runtime::class, 'mode');
        $previousMode = $runtimeMode->getValue();
        $runtimeMode->setValue(null, \Weline\Framework\Runtime\Runtime::WLS);
        try {
            $observer->execute($event);
            $observer->execute($event);
            $response = $event->getData('response');
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(1, substr_count($response->getBody(), 'name="weline-worker-scope-bootstrap"'));
            self::assertCount(1, $response->getCookies());
            self::assertSame(1, TestBootstrapScopeProvider::$issueCalls);
        } finally {
            $runtimeMode->setValue(null, $previousMode);
        }
    }

    public function testServerErrorHtmlDoesNotIssueBootstrap(): void
    {
        $store = new BootstrapMemoryStateStore();
        $service = new FrontendWorkerScopeBootstrapResponseService(
            new TestBootstrapScopeProvider(), new FrontendWorkerSessionService($store),
        );
        $response = Response::html('<html><head></head><body>Error</body></html>', 500);
        self::assertSame($response, $service->decorate($response));
        self::assertSame([], $response->getCookies());
        self::assertSame(0, TestBootstrapScopeProvider::$issueCalls);
        self::assertSame(0, $store->transactions);
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
    public static ?\Throwable $issueException = null;

    public static function reset(): void
    {
        self::$mode = FrontendWorkerScopeRolloutDecision::MODE_ON;
        self::$binding = null;
        self::$issueCalls = 0;
        self::$issueException = null;
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
        if (self::$issueException !== null) {
            throw self::$issueException;
        }
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
