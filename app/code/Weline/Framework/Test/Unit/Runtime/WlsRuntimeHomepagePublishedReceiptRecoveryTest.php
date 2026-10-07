<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Server\Runtime\WlsRuntime;

/** Real Coordinator publication/file storage and Runtime transaction; no shared sidecar. */
final class WlsRuntimeHomepagePublishedReceiptRecoveryTest extends TestCase
{
    public function testBusyWorkerCanScheduleOnlyProofRecovery(): void
    {
        $runtime = new WlsRuntime();
        (new \ReflectionProperty($runtime, 'readyGateWorkerBootstrapWarmupCompleted'))->setValue($runtime, true);
        self::assertFalse($runtime->shouldScheduleHomepageKeepWarm(1000, false, false));
        self::assertTrue(\method_exists($runtime, 'shouldScheduleHomepageProofRecovery')
            && $runtime->shouldScheduleHomepageProofRecovery(false, false),
            'continuous foreground traffic must not starve proof-only receipt discovery');
        self::assertFalse($runtime->shouldScheduleHomepageProofRecovery(true, false));
        self::assertFalse($runtime->shouldScheduleHomepageProofRecovery(false, true));
        (new \ReflectionProperty($runtime, 'homepageProofNextAtNs'))->setValue($runtime, \hrtime(true) + 60_000_000_000);
        $next = (new \ReflectionProperty($runtime, 'homepageProofNextAtNs'))->getValue($runtime);
        $runtime->noteHomepageNaturalHit('/');
        self::assertSame($next, (new \ReflectionProperty($runtime, 'homepageProofNextAtNs'))->getValue($runtime), 'public HIT must not postpone independent proof due');
    }

    public static function publishedReceiptVariants(): array
    {
        return [['valid'], ['guard-clear-republish'], ['guard-stale-candidate'], ['origin'], ['namespace'], ['policy'], ['translation'], ['scope'], ['cookie'], ['missing-body'], ['missing-receipt'], ['generation']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishedReceiptVariants')]
    public function testEmptyPeerConsumesPublishedExactReceiptWithoutPrime(string $variant): void
    {
        $originalServer = $_SERVER;
        $originalCoordinator = ObjectManager::_getInstance(FullPageCacheCoordinator::class);
        $originalHotCache = ObjectManager::_getInstance(StorefrontScopeHotCache::class);
        $overrideClass = \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class;
        $originalOverride = ObjectManager::_getInstance($overrideClass);
        $eventsClass = \Weline\Framework\Event\EventsManager::class;
        $originalEvents = ObjectManager::_getInstance($eventsClass);
        $stateProperty = new \ReflectionProperty(WlsRuntime::class, 'dynamicWarmupCoordinator');
        $resolvedProperty = new \ReflectionProperty(WlsRuntime::class, 'dynamicWarmupCoordinatorResolved');
        $oldState = $stateProperty->getValue();
        $oldResolved = $resolvedProperty->getValue();
        $candidateProperty = new \ReflectionProperty(WlsRuntime::class, 'homepagePublicationProofCandidate');
        $oldCandidate = $candidateProperty->getValue();
        $candidateProperty->setValue(null, []);
        $path = BP . 'dev/tmp/fpc-recovery-' . \bin2hex(\random_bytes(8));
        $payloadAdapter = new FileAdapter('payload', ['path' => $path]);
        $stateAdapter = new FileAdapter('coordination', ['path' => $path]);
        $state = new HomepageReceiptFileState($stateAdapter, $path . '/coordination.lock');
        try {
            // 系统配置覆盖是fixture边界；仍使用真实默认安全头与canonical格式。
            ObjectManager::setInstance($overrideClass, $this->createStub($overrideClass));
            ObjectManager::setInstance($eventsClass, $this->createStub($eventsClass));
            $_SERVER = ['WLS_INSTANCE_NAME' => 'fpc-recovery-test', 'argv' => ['test', '--epoch=fixture-1', '--cache-epoch=fixture-1']];
            WelineEnv::getInstance()->reset();
            \Weline\Framework\Http\HeaderCollector::reset();
            Runtime::setMode(Runtime::WLS);
            FullPageCacheCoordinator::clearProcessCache();
            Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
            RequestContext::setId('homepage-recovery-producer');
            RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL));
            RequestContext::setWelineUserLang('zh_Hans_CN');
            RequestContext::setWelineUserCurrency('CNY');
            Context::current()->set('input.server.HTTP_HOST', 'fpc-recovery.test');
            Context::current()->set('input.host', 'fpc-recovery.test');
            // 全路径共用当前配置的只读权威；payload/协调写入均限本测试FileAdapter。
            $authority = ObjectManager::getInstance(\Weline\Framework\Cache\Namespace\NamespaceGenerationRepository::class);
            ObjectManager::setInstance(StorefrontScopeHotCache::class, new StorefrontScopeHotCache(generations: $authority));
            $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
            // 固定测试策略快照走真实解释器；避免访问任何默认共享缓存。
            ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
                'framework.fpc_policy_snapshot', 'snapshot.v1', static fn(): array => ['declarations' => [], 'overrides' => [], 'scope_chains' => []]
            );
            $resolver->freezeCurrent();
            $origin = 'https://fpc-recovery.test/';
            WelineEnv::set('full_request_uri', $origin, 'fpc-recovery-fixture');
            WelineEnv::setServer('WELINE_FULL_REQUEST_URI', $origin, 'fpc-recovery-fixture');
            WelineEnv::set('request.uri', '/', 'fpc-recovery-fixture');
            WelineEnv::set('request.method', 'GET', 'fpc-recovery-fixture');
            WelineEnv::set('is_backend', false, 'fpc-recovery-fixture');
            WelineEnv::set('is_static_file', false, 'fpc-recovery-fixture');
            $payloadPool = new CachePool('fixture-fpc', $payloadAdapter);
            $coordinator = new FullPageCacheCoordinator(cachePool: $payloadPool, storefrontCacheKeyContextResolver: $resolver);
            ObjectManager::setInstance(FullPageCacheCoordinator::class, $coordinator);
            $body = '<!doctype html><html><head><title>Public receipt fixture</title></head><body>Anonymous homepage</body></html>';
            $response = new Response();
            $response->setHeader('Content-Type', 'text/html; charset=utf-8');
            $response->setBody($body);
            $coordinator->publishResponse($response, '/', [], [], []);
            $body = $response->getBody(); // 生产发布会按现有CSP策略装饰HTML。
            $receipt = $coordinator->resolveRootHomepageProcessReceipt($origin);
            self::assertIsArray($receipt, 'producer must publish a real canonical receipt before recovery is tested');
            self::assertSame(2, $receipt['version']);
            self::assertSame($receipt, $candidateProperty->getValue(), 'actual canonical publication enqueues only its exact receipt');

            $stateProperty->setValue(null, $state);
            $resolvedProperty->setValue(null, true);
            $producer = new WlsRuntime();
            (new \ReflectionProperty($producer, 'homepageCacheFullUri'))->setValue($producer, $origin);
            $pool = (new \ReflectionMethod($producer, 'homepageWarmupCoordinationPool'))->invoke($producer, $origin);
            self::assertNull($state->getCache($pool, 'ready'), 'canonical publication itself must not execute shared coordination RPC');
            $producerRun = $this->runCooperativeProof($producer);
            self::assertTrue($producerRun['ok'], 'real canonical producer background proof must publish: ' . $producerRun['reason']);
            self::assertGreaterThan(2, $producerRun['foreground_ticks'], 'foreground must advance between proof stages');
            self::assertSame($receipt, $state->getCache($pool, 'ready')['receipt']);
            self::assertNull($state->getCache($pool, 'owner'), 'deferred publisher must release its owned fence');
            if (\in_array($variant, ['origin', 'namespace', 'policy', 'translation', 'scope', 'cookie'], true)) {
                $published = $state->getCache($pool, 'ready');
                $field = ['origin' => 'full_uri', 'namespace' => 'namespace_fingerprint', 'policy' => 'policy_fingerprint', 'translation' => 'translation_locales', 'scope' => 'scope_identity', 'cookie' => 'cookie_header'][$variant];
                $value = ['origin' => 'https://other-origin.test/', 'namespace' => \str_repeat('f', 64), 'policy' => 'invalid-policy', 'translation' => ['invalid_LOCALE'],
                    'scope' => ScopeIdentity::channel(0, 'default', 'default', 'other', ScopeIdentity::MODE_NORMAL)->toArray(), 'cookie' => 'LANG=en_US'][$variant];
                $published['receipt'][$field] = $value;
                if ($variant !== 'origin') {
                    self::assertFalse((new \ReflectionMethod($producer, 'publishDeferredHomepageWarmupReceipt'))->invoke($producer, $published['receipt']));
                }
                $state->setCache($pool, 'ready', $published, 120);
            } elseif ($variant === 'missing-body') {
                $payloadPool->delete($receipt['cache_key']);
                self::assertFalse((new \ReflectionMethod($producer, 'publishDeferredHomepageWarmupReceipt'))->invoke($producer, $receipt), 'Process-only old body cannot become shared ready');
            } elseif ($variant === 'missing-receipt') {
                $state->deleteCache($pool, 'ready');
            } elseif ($variant === 'generation') {
                $_SERVER['argv'] = ['test', '--epoch=fixture-2', '--cache-epoch=fixture-1'];
            }
            FullPageCacheCoordinator::clearProcessCache();
            $candidateProperty->setValue(null, []); // distinct cold peer has no producer-local candidate.
            RequestContext::cleanup();
            Context::leave();
            Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
            RequestContext::setId('homepage-recovery-peer');
            // 新peer没有Website/Store/Channel/Session，不从请求猜缓存身份。
            ObjectManager::getInstance(StorefrontScopeHotCache::class)->rememberForRequest(
                'framework.fpc_policy_snapshot', 'snapshot.v1', static fn(): array => ['declarations' => [], 'overrides' => [], 'scope_chains' => []]
            );
            $peer = new WlsRuntime();
            (new \ReflectionProperty($peer, 'homepageCacheFullUri'))->setValue($peer, $origin);
            // 模拟fail-open新peer：只有无精确key的旧fallback，Process完全为空。
            (new \ReflectionProperty($peer, 'homepageCacheWarmupReceipt'))->setValue($peer, [
                'version' => 1, 'full_uri' => $origin, 'method' => 'GET', 'cookie_header' => '', 'identity_digest' => \hash('sha256', 'fixture-fallback'),
            ]);
            if ($variant === 'valid' || \str_starts_with($variant, 'guard-') || $variant === 'missing-receipt') {
                $recovery = $this->runCooperativeProof($peer);
                $transaction = ['validation' => $recovery];
                self::assertGreaterThan(2, $recovery['foreground_ticks']);
                (new \ReflectionProperty($peer, 'readyGateWorkerBootstrapWarmupCompleted'))->setValue($peer, true);
                self::assertFalse($peer->shouldScheduleHomepageProofRecovery(false, false), 'success and failure both advance independent due');
                if ($variant === 'missing-receipt') {
                    self::assertGreaterThanOrEqual(2950, $recovery['elapsed_ms']);
                    self::assertLessThan(4500, $recovery['elapsed_ms'], 'bounded observation includes all timer waits and discloses IO tail');
                }
            } else {
                $transaction = (new \ReflectionMethod($peer, 'runHomepageFpcWarmupTransaction'))->invoke($peer, 'fpc-recovery.test', 1, false);
            }
            if ($variant !== 'valid' && !\str_starts_with($variant, 'guard-')) {
                self::assertFalse($transaction['validation']['ok'], $variant . ' must retain fail-open MISS');
                self::assertNull($peer->resolveHomepageFastPathReceipt($origin));
                self::assertFalse((bool)((new \ReflectionProperty($peer, 'readyGateHomepageFpcProof'))->getValue($peer)['hit'] ?? false));
                return;
            }
            self::assertTrue($transaction['validation']['ok'], 'published exact v2 must hydrate an empty peer without Router/SSR; observed=' . $transaction['validation']['reason']);
            self::assertSame($receipt, $peer->resolveHomepageFastPathReceipt($origin));
            self::assertTrue((new \ReflectionProperty($peer, 'readyGateHomepageFpcProof'))->getValue($peer)['hit']);
            if (\str_starts_with($variant, 'guard-')) {
                FullPageCacheCoordinator::clearProcessCache();
                self::assertNull($coordinator->resolveRootHomepageProcessReceipt($origin));
                $state->deleteCache($pool, 'ready'); // discovery TTL expired; actual payload still fresh.
                self::assertSame([], $candidateProperty->getValue(), 'follower has no producer-local candidate');
                if ($variant === 'guard-stale-candidate') {
                    $staleCandidate = $receipt;
                    $staleCandidate['namespace_fingerprint'] = \str_repeat('f', 64);
                    $candidateProperty->setValue(null, $staleCandidate);
                }
                (new \ReflectionProperty($peer, 'homepageProofNextAtNs'))->setValue($peer, 0);
                $refresh = $this->runCooperativeProof($peer);
                self::assertTrue($refresh['ok']);
                self::assertSame($receipt, $state->getCache($pool, 'ready')['receipt'] ?? null,
                    'known follower must restore discovery proof after Guard cleared root registry and ready TTL elapsed');
            }
            foreach (['GET', 'HEAD'] as $method) {
                $hit = $coordinator->getFormattedProcessCachedResponseForInternalReceipt($receipt, true, $method, 'text/html', 'gzip');
                self::assertIsArray($hit);
                self::assertStringStartsWith('HTTP/1.1 200 ', $hit['response']);
                $decoded = \explode("\r\n\r\n", $hit['response'], 2)[1];
                self::assertSame($method === 'HEAD' ? '' : $body, $method === 'HEAD' ? $decoded : \gzdecode($decoded));
            }
        } finally {
            $stateProperty->setValue(null, $oldState);
            $resolvedProperty->setValue(null, $oldResolved);
            $candidateProperty->setValue(null, $oldCandidate);
            if ($originalCoordinator === null) { ObjectManager::removeInstance(FullPageCacheCoordinator::class); }
            else { ObjectManager::setInstance(FullPageCacheCoordinator::class, $originalCoordinator); }
            if ($originalHotCache === null) { ObjectManager::removeInstance(StorefrontScopeHotCache::class); }
            else { ObjectManager::setInstance(StorefrontScopeHotCache::class, $originalHotCache); }
            if ($originalOverride === null) { ObjectManager::removeInstance($overrideClass); }
            else { ObjectManager::setInstance($overrideClass, $originalOverride); }
            if ($originalEvents === null) { ObjectManager::removeInstance($eventsClass); }
            else { ObjectManager::setInstance($eventsClass, $originalEvents); }
            if (Context::hasCurrent()) { RequestContext::cleanup(); Context::leave(); }
            WelineEnv::getInstance()->reset();
            \Weline\Framework\Http\HeaderCollector::reset();
            FullPageCacheCoordinator::clearProcessCache();
            Runtime::resetModeCache();
            $_SERVER = $originalServer;
            $payloadAdapter->clear();
            $stateAdapter->clear();
            @\unlink($path . '/coordination.lock');
            @\rmdir($path . '/payload');
            @\rmdir($path . '/coordination');
            @\rmdir($path);
        }
    }

    private function runCooperativeProof(WlsRuntime $runtime): array
    {
        $schedulerProperties = [];
        foreach (['schedulerActive', 'ioWaitEnabled', 'waitDispatcher', 'foregroundBusyProbe', 'backgroundMaxParkMs'] as $name) {
            $property = new \ReflectionProperty(SchedulerSystem::class, $name);
            $schedulerProperties[$name] = [$property, $property->getValue()];
        }
        $delay = 0;
        $ticks = 0;
        try {
            SchedulerSystem::enableScheduler();
            SchedulerSystem::enableIoWait();
            SchedulerSystem::setForegroundBusyProbe(static fn(): bool => true, 1);
            SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use (&$delay): void {
                $delay = (int)($params['milliseconds'] ?? 0);
            });
            $fiber = new \Fiber(static fn(): array => $runtime->runHomepageProofRecoveryCycle());
            $fiber->start();
            while ($fiber->isSuspended()) {
                ++$ticks; // neighboring foreground tick, not a mock core response.
                if ($delay > 0) { \usleep($delay * 1000); }
                $fiber->resume(true);
            }
            $result = $fiber->getReturn();
            $result['foreground_ticks'] = $ticks;
            return $result;
        } finally {
            foreach ($schedulerProperties as [$property, $value]) { $property->setValue(null, $value); }
        }
    }
}

/** File-backed test coordination boundary; production CAS/fence logic stays in WlsRuntime. */
final class HomepageReceiptFileState implements SharedCacheStateInterface
{
    public function __construct(private FileAdapter $adapter, private string $lockPath) {}
    private function key(string $namespace, string $key): string { return \hash('sha256', $namespace . '|' . $key); }
    public function get(string $namespace, string $key): mixed { return $this->adapter->get($this->key($namespace, $key)); }
    public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool { return $this->adapter->set($this->key($namespace, $key), $value, $ttl); }
    public function delete(string $namespace, string $key): bool { return $this->adapter->delete($this->key($namespace, $key)); }
    public function exists(string $namespace, string $key): bool { return $this->get($namespace, $key) !== null; }
    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int { throw new \LogicException('Not used by homepage transaction'); }
    public function cas(string $namespace, string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        $lock = \fopen($this->lockPath, 'c');
        try {
            if (!\flock($lock, LOCK_EX)) { return false; }
            if ($this->get($namespace, $key) !== $expected) { return false; }
            return $value === null ? $this->delete($namespace, $key) : $this->set($namespace, $key, $value, $ttl);
        } finally { \flock($lock, LOCK_UN); \fclose($lock); }
    }
    public function clearNamespace(string $namespace): bool { throw new \LogicException('No shared clearing in recovery test'); }
    public function getCache(string $poolIdentity, string $key): mixed { return $this->get($poolIdentity, $key); }
    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool { return $this->set($poolIdentity, $key, $value, $ttl); }
    public function deleteCache(string $poolIdentity, string $key): bool { return $this->delete($poolIdentity, $key); }
    public function hasCache(string $poolIdentity, string $key): bool { return $this->exists($poolIdentity, $key); }
    public function clearCache(string $poolIdentity): bool { return $this->clearNamespace($poolIdentity); }
    public function compareAndSetCache(string $poolIdentity, string $key, mixed $expected, mixed $value, int $ttl = 0): bool { return $this->cas($poolIdentity, $key, $expected, $value, $ttl); }
    public function disconnect(): void {}
}
