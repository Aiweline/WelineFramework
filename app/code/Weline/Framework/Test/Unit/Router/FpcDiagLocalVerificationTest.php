<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Router\FpcDiag;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * FPC 诊断在真实决策路径上的端到端校验。
 *
 * 目的：确认启用 `__fpcdiag=1` 后，能够在真实代码路径（StorefrontCacheKeyContext
 * 降级、canPublishResponse 门槛、effectiveFpcPolicy 策略）上拿到可判定的输出，
 * 而不是只能靠猜响应头。
 */
final class FpcDiagLocalVerificationTest extends TestCase
{
    private string $logPath = '';

    /** @var array<string, mixed> */
    private array $originalIsolatedServices = [];

    /** @var array<int|string, mixed> */
    private array $originalServer = [];

    private mixed $originalAllowedCurrencyCodeMap = null;

    protected function setUp(): void
    {
        $this->logPath = \sys_get_temp_dir() . '/fpc-diag-local-' . \getmypid() . '.log';
        @\unlink($this->logPath);
        $_GET = ['__fpcdiag' => '1'];
        $_COOKIE = [];
        FpcDiag::useLogPath($this->logPath);
        FpcDiag::reset();
        FpcDiag::armFromRequest('/?__fpcdiag=1');

        $isolated = [
            \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class
                => $this->createStub(\Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class),
            \Weline\Framework\Event\EventsManager::class
                => $this->createStub(\Weline\Framework\Event\EventsManager::class),
        ];
        foreach ($isolated as $class => $service) {
            $this->originalIsolatedServices[$class] = \Weline\Framework\Manager\ObjectManager::_getInstance($class);
            \Weline\Framework\Manager\ObjectManager::setInstance($class, $service);
        }

        $this->originalServer = $_SERVER;
        $_SERVER = [];
        if (Context::hasCurrent()) {
            Context::leave();
        }
        WelineEnv::getInstance()->reset();
        Runtime::setMode(Runtime::WLS);
        FullPageCacheCoordinator::clearProcessCache();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('fpc-diag-local-test');
        Context::current()->set('input.server.HTTP_HOST', 'example.test');
        Context::current()->set('input.server.SERVER_PORT', 443);
        Context::current()->set('input.host', 'example.test');
        WelineEnv::set('full_request_uri', 'https://example.test/', 'unit-test');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', 'https://example.test/', 'unit-test');
        WelineEnv::set('request.uri', '/', 'unit-test');
        WelineEnv::set('request.method', 'GET', 'unit-test');
        WelineEnv::set('is_backend', false, 'unit-test');
        WelineEnv::set('is_static_file', false, 'unit-test');

        $currencyMap = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope');
        $this->originalAllowedCurrencyCodeMap = $currencyMap->getValue();
        $scope = (new \ReflectionMethod(State::class, 'currentWebsiteScopeKey'))->invoke(null);
        $currencyMap->setValue(null, [$scope => ['CNY' => true, 'USD' => true]]);
    }

    protected function tearDown(): void
    {
        @\unlink($this->logPath);
        $_GET = [];
        $_COOKIE = [];
        FpcDiag::useLogPath(null);
        FpcDiag::reset();

        foreach ($this->originalIsolatedServices as $class => $service) {
            if ($service !== null) {
                \Weline\Framework\Manager\ObjectManager::setInstance($class, $service);
            } else {
                \Weline\Framework\Manager\ObjectManager::removeInstance($class);
            }
        }
        (new \ReflectionProperty(State::class, 'allowedCurrencyCodeMapsByScope'))
            ->setValue(null, $this->originalAllowedCurrencyCodeMap);
        WelineEnv::getInstance()->reset();
        FullPageCacheCoordinator::clearProcessCache();
        Runtime::resetModeCache();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        $_SERVER = $this->originalServer;
    }

    /**
     * 关键信号：店面缓存上下文未解析时必须留下 failure_code —— 这正是
     * 「payload 持续增长却 100% MISS」的成因所在。
     */
    public function testMissingStorefrontContextIsReportedAsFence(): void
    {
        // 本测试刻意不安装任何 StorefrontCacheKeyContext，模拟生产上的降级状态。
        $context = StorefrontCacheKeyContext::currentOrRequestFence('unit_fence_probe');

        self::assertFalse($context->cacheable, '未解析上下文必须不可缓存');
        self::assertFalse($context->hasCompleteFrozenScope(), '未解析上下文不得被当作完整冻结范围');

        $fence = $this->firstEvent('storefront_context_fence');
        self::assertNotNull($fence, '未解析上下文必须留下诊断事件，否则线上无法定位');
        self::assertSame('unit_fence_probe', $fence['data']['failure_code']);
        self::assertFalse($fence['data']['context_scope_identity']);
        self::assertFalse($fence['data']['request_scope_identity']);
    }

    /** 发布门槛的诊断必须能区分「可共享」与「不可共享」，并带上实际头部。 */
    public function testPublishGateDiagnosticsDistinguishShareableFromPrivate(): void
    {
        $this->installFrozenStorefrontContext();
        $coordinator = $this->coordinator();

        $public = Response::html('<html>public</html>')->setHeader('Cache-Control', 'public, max-age=60');
        self::assertTrue($coordinator->canPublishResponse($public, 'GET'));

        $private = Response::html('<html>private</html>')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->setHeader('Pragma', 'no-cache');
        self::assertFalse($coordinator->canPublishResponse($private, 'GET'));

        $gates = \array_values(\array_filter(
            $this->diagEvents(),
            static fn(array $e): bool => $e['event'] === 'can_publish',
        ));
        self::assertNotEmpty($gates, '发布门槛必须留下诊断事件');

        $first = $gates[0]['data'];
        self::assertTrue($first['allowed']);
        self::assertSame('ok', $first['gate']);

        $last = \end($gates)['data'];
        self::assertFalse($last['allowed']);
        self::assertSame('response_not_shareable', $last['gate']);
        self::assertStringContainsString('private', (string)$last['cache_control']);
        self::assertSame('no-cache', (string)$last['pragma']);
    }

    /** 渲染器调用 forbid() 时必须记录原因码 —— 这是「整页变 no-store」的直接证据。 */
    public function testForbiddenReasonIsCapturedWhenRendererForbidsSharedCache(): void
    {
        $this->installFrozenStorefrontContext();
        $coordinator = $this->coordinator();

        SharedResponseCachePolicy::forbid('unit_probe_reason');
        $response = Response::html('<html>forbidden</html>')->setHeader('Cache-Control', 'public');
        self::assertFalse($coordinator->canPublishResponse($response, 'GET'));

        $forbidden = null;
        foreach ($this->diagEvents() as $event) {
            if (($event['data']['gate'] ?? '') === 'shared_response_cache_forbidden') {
                $forbidden = $event['data'];
            }
        }
        self::assertNotNull($forbidden, 'forbid() 必须被诊断捕获，否则无法定位是谁让整页不可缓存');
        self::assertContains('unit_probe_reason', $forbidden['reasons']);
    }

    private function installFrozenStorefrontContext(): void
    {
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            RequestContext::scopeIdentity(),
            'zh_Hans_CN',
            'CNY',
            \str_repeat('b', 64),
            \str_repeat('b', 64),
            true,
        ));
    }

    private function coordinator(): FullPageCacheCoordinator
    {
        $authority = new class implements NamespaceGenerationInterface {
            public function fingerprint(array $namespaces): string
            {
                return \str_repeat('b', 64);
            }

            public function bump(string $namespace): array
            {
                return [];
            }

            public function bumpMany(array $namespaces): array
            {
                return [];
            }
        };

        return new FullPageCacheCoordinator(
            cachePool: $this->createStub(CachePoolInterface::class),
            storefrontCacheKeyContextResolver: new StorefrontCacheKeyContextResolver($authority, new NamespacePath()),
        );
    }

    /** @return list<array<string, mixed>> */
    private function diagEvents(): array
    {
        if (!\is_file($this->logPath)) {
            return [];
        }

        $events = [];
        foreach (\array_filter(\explode("\n", (string)\file_get_contents($this->logPath))) as $line) {
            $decoded = \json_decode($line, true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstEvent(string $name): ?array
    {
        foreach ($this->diagEvents() as $event) {
            if (($event['event'] ?? '') === $name) {
                return $event;
            }
        }

        return null;
    }
}
