<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Service\CacheMemoryService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Service\SharedMemoryService;

/** Controlled transport failure; this does not attribute past live responses. */
final class WorkerCasAmbiguousCommitTest extends TestCase
{
    public function testCommittedNonceWithLostReplyDoesNotReplayConsumption(): void
    {
        $state = [];
        $loseReply = false;
        $lostCommits = 0;
        $readsAfterLostCommit = 0;
        $client = $this->createMock(SharedStateClient::class);
        $client->method('request')->willReturnCallback(static function (string $command, array $params) use (&$state, &$loseReply, &$lostCommits, &$readsAfterLostCommit): array {
            $ns = $params['ns'];
            $key = $params['key'];
            if ($command === SessionProtocol::CMD_GET) {
                if ($lostCommits > 0) { ++$readsAfterLostCommit; }
                return ['ok' => true, 'data' => $state[$ns][$key] ?? null];
            }
            if ($command !== SessionProtocol::CMD_COMPARE_SET) {
                throw new \LogicException('Unexpected fixture command.');
            }
            if (($state[$ns][$key] ?? null) !== $params['expected']) {
                return ['ok' => false, 'error' => 'CAS failed: value mismatch'];
            }
            $state[$ns][$key] = $params['val'];
            if ($loseReply) {
                $loseReply = false;
                ++$lostCommits;
                throw new \RuntimeException('shared_state_transport_failed');
            }
            return ['ok' => true];
        });
        // Inject only the transport. Actual service/facade/Server adapter and
        // Atomic store implement all read, CAS and credential behavior.
        $reflection = new \ReflectionClass(SharedMemoryService::class);
        $memory = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($memory, $client);
        $facade = new MemoryStateFacade([
            'host' => '127.0.0.1', 'port' => 47399,
            'token_file_name' => 'isolated_lost_cas_reply.token', 'prefer_direct_connect' => true,
        ], null, $memory, new CacheMemoryService($memory), $client);
        $adapter = new WlsMemoryAdapter('isolated_lost_cas_reply', [], $facade);
        $service = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'cache:wls_memory'));
        $session = $service->createSession('test-deploy', 'test-build');
        $loseReply = true;
        try {
            $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-first-use');
            self::fail('Expected an unknown write outcome without consuming again.');
        } catch (FrontendQueryException $error) {
            self::assertSame('worker_store_unavailable', $error->getErrorCode());
        }
        self::assertSame(1, $lostCommits);
        self::assertSame(0, $readsAfterLostCommit);
        $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'test-deploy', 'test-build', 'isolated-next-use');
        self::assertTrue(true, 'A distinct nonce still commits; no replay check was bypassed.');
    }
}
