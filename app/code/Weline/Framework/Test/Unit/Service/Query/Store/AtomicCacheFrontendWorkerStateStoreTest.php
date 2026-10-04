<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query\Store;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Service\CacheMemoryService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Service\SharedMemoryService;

final class AtomicCacheFrontendWorkerStateStoreTest extends TestCase
{
    public function testKnownUndispatchedCasCanRetryWithoutDoubleConsumption(): void
    {
        [, $store, $control] = $this->fixture();
        $faultBackend = $this->createMock(\Weline\Framework\Cache\Contract\SharedCacheStateInterface::class);
        $faultBackend->method('getCache')->willThrowException(new \RuntimeException('isolated_transport_failure'));
        $fault = new WlsMemoryAdapter('isolated_before_send_' . spl_object_id($control), [], $faultBackend);
        $callbacks = 0;
        $answer = $store->transaction(static function (array &$state) use ($fault, &$callbacks): int {
            if (++$callbacks === 1) { $fault->get('isolated-key'); }
            $state['count'] = (int)($state['count'] ?? 0) + 1;
            return $state['count'];
        });
        self::assertSame(1, $answer);
        self::assertSame(2, $callbacks);
        self::assertSame(1, $control->cas, '未发送的尝试不能算作后端写入或凭据消费');
        self::assertSame(['count' => 1], array_values($control->state)[0]['worker_state.v1']);
        WlsMemoryAdapter::clearAllMemory();
    }

    public function testConfirmedCasConflictSurvivesUnrelatedCacheFailure(): void
    {
        [, $store, $control] = $this->fixture();
        $faultBackend = $this->createMock(\Weline\Framework\Cache\Contract\SharedCacheStateInterface::class);
        $faultBackend->method('getCache')->willThrowException(new \RuntimeException('isolated_transport_failure'));
        $fault = new WlsMemoryAdapter('isolated_other_pool_' . spl_object_id($control), [], $faultBackend);
        $control->onCas = static function (string $namespace, string $key) use ($control, $fault): void {
            $control->onCas = null;
            // 另一 Worker 修改权威快照，当前 CAS 明确返回冲突；独立缓存操作同时触发冷却。
            $control->state[$namespace][$key] = ['parallel' => true];
            $fault->get('isolated-key');
        };
        $answer = $store->transaction(static function (array &$state): int {
            $state['count'] = (int)($state['count'] ?? 0) + 1;
            return $state['count'];
        });
        self::assertSame(1, $answer);
        self::assertSame(2, $control->cas);
        $wire = array_values($control->state)[0]['worker_state.v1'];
        self::assertSame(['parallel' => true, 'count' => 1], $wire);
        WlsMemoryAdapter::clearAllMemory();
    }

    public function testLostReplyNeverReplaysCommittedNonceConsumption(): void
    {
        [$service, , $control] = $this->fixture();
        $session = $service->createSession('test-deploy', 'test-build');
        $beforeCas = $control->cas;
        $control->onCas = static function (string $namespace, string $key, mixed $wire) use ($control): void {
            $control->onCas = null;
            // 后端已提交消费，但原始回复丢失；不能内部重放消费来猜测结果。
            $control->state[$namespace][$key] = $wire;
            throw new \Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException('isolated_lost_ack');
        };
        try {
            $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-lost-ack');
            self::fail('失去确认回复必须保留不可确认结果。');
        } catch (FrontendQueryException $error) {
            self::assertSame('worker_store_unavailable', $error->getErrorCode());
            self::assertInstanceOf(\Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException::class, $error->getPrevious());
        }
        self::assertSame($beforeCas + 1, $control->cas);
        try {
            $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-lost-ack');
            self::fail('已提交消费的 nonce 不能再次使用。');
        } catch (FrontendQueryException $error) {
            self::assertSame('auth_error', $error->getErrorCode());
        }
        self::assertSame($beforeCas + 1, $control->cas);
        WlsMemoryAdapter::clearAllMemory();
    }

    public function testRealSessionReadonlyValidationDoesNotCasOrRenewTtl(): void
    {
        [$service, $store, $control] = $this->fixture();
        $session = $service->createSession('test-deploy', 'test-build');
        $before = $control->state;
        $cas = $control->cas;
        $ttls = $control->ttls;

        $data = $service->validateSession($session['worker_session_token'], 'test-deploy', 'test-build');

        self::assertArrayHasKey('secret', $data);
        self::assertSame($before, $control->state);
        self::assertSame($cas, $control->cas);
        self::assertSame($ttls, $control->ttls);
    }

    public function testInitiallyMissingReadonlyStateDoesNotCreateEmptyKey(): void
    {
        [$service, $store, $control] = $this->fixture();
        $answer = $store->transaction(static fn (array &$state): int => 42);

        self::assertSame(42, $answer);
        self::assertSame(0, $control->cas);
        self::assertSame([], $control->state);
    }

    public function testMutableNonceStillCasAndReplayIsRejected(): void
    {
        [$service, $store, $control] = $this->fixture();
        $session = $service->createSession('test-deploy', 'test-build');
        $cas = $control->cas;

        $service->validateSessionAndConsumeNonce(
            $session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-first',
        );
        self::assertSame($cas + 1, $control->cas);
        try {
            $service->validateSessionAndConsumeNonce(
                $session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-first',
            );
            self::fail('Nonce replay must be rejected.');
        } catch (FrontendQueryException $error) {
            self::assertSame('auth_error', $error->getErrorCode());
        }
        self::assertSame($cas + 1, $control->cas);
    }

    public function testNonFreshAdapterStillConfirmsReadonlySnapshotWithCas(): void
    {
        $adapter = $this->createMock(AtomicCacheAdapterInterface::class);
        $adapter->method('get')->willReturn([]);
        $adapter->expects(self::once())->method('compareAndSet')->willReturn(true);

        self::assertSame(42, (new AtomicCacheFrontendWorkerStateStore($adapter))->transaction(
            static fn (array &$state): int => 42,
        ));
    }

    public function testRevocationAfterReadonlySnapshotStillRejectsSecondConsumption(): void
    {
        [$service, $store, $control] = $this->fixture();
        $session = $service->createSession('test-deploy', 'test-build');
        $service->validateSession($session['worker_session_token'], 'test-deploy', 'test-build');

        // 合成共享后端模拟另一 Worker 在两次事务之间撤销，不触及真实缓存。
        $control->state = [];
        $cas = $control->cas;
        try {
            $service->validateSessionAndConsumeNonce(
                $session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-after-revoke',
            );
            self::fail('Revoked session must be rejected.');
        } catch (FrontendQueryException $error) {
            self::assertSame('auth_error', $error->getErrorCode());
        }
        self::assertSame($cas, $control->cas);
        self::assertSame([], $control->state);
    }

    /** 只替换传输，使用真实 Service、Server adapter 和权威读取路径。 */
    private function fixture(): array
    {
        $control = (object)['state' => [], 'cas' => 0, 'reads' => 0, 'ttls' => [], 'onCas' => null];
        $client = $this->createMock(SharedStateClient::class);
        $client->method('request')->willReturnCallback(
            static function (string $command, array $params) use ($control): array {
                $namespace = $params['ns'];
                $key = $params['key'];
                if ($command === SessionProtocol::CMD_GET) {
                    ++$control->reads;
                    return ['ok' => true, 'data' => $control->state[$namespace][$key] ?? null];
                }
                if ($command !== SessionProtocol::CMD_COMPARE_SET) {
                    throw new \LogicException('Unexpected isolated command.');
                }
                ++$control->cas;
                if ($control->onCas !== null) { ($control->onCas)($namespace, $key, $params['val']); }
                $control->ttls[] = $params['ttl'];
                if (($control->state[$namespace][$key] ?? null) !== $params['expected']) {
                    return \json_decode(SessionProtocol::encodeError('CAS failed: value mismatch'), true);
                }
                $control->state[$namespace][$key] = $params['val'];
                return ['ok' => true];
            },
        );
        $reflection = new \ReflectionClass(SharedMemoryService::class);
        $memory = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($memory, $client);
        $facade = new MemoryStateFacade([
            'host' => '127.0.0.1', 'port' => 47399,
            'token_file_name' => 'isolated_readonly.token', 'prefer_direct_connect' => true,
        ], null, $memory, new CacheMemoryService($memory), $client);
        $store = new AtomicCacheFrontendWorkerStateStore(
            new WlsMemoryAdapter('isolated_readonly_' . \spl_object_id($control), [], $facade),
            'cache:wls_memory',
        );

        return [new FrontendWorkerSessionService($store), $store, $control];
    }
}
