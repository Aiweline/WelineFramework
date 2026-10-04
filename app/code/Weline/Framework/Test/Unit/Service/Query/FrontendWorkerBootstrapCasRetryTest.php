<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\FreshCacheReadInterface;
use Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

/** Real service/AtomicStore/ArrayCredentialTransaction with a bounded CAS fault boundary. */
final class FrontendWorkerBootstrapCasRetryTest extends TestCase
{
    public static function attempts(): array
    {
        return [['scope', 'commit-without-ack'], ['backend', 'commit-without-ack'],
            ['scope', 'no-commit'], ['backend', 'no-commit'],
            ['scope', 'never-ack'], ['backend', 'never-ack']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attempts')]
    public function testEachBootstrapTransactionAttemptHasItsOwnCredential(string $kind, string $fault): void
    {
        $path = BP . 'dev/tmp/bootstrap-cas-' . \bin2hex(\random_bytes(8));
        $files = new FileAdapter('state', ['path' => $path]);
        $adapter = new BootstrapCasFaultAdapter($files, $path . '/state.lock', $fault);
        $service = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'test-cas', 600));
        $now = \time();
        $binding = $kind === 'scope' ? new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test', \hash('sha256', 'fixture-token'), $now, $now + 1800, true,
        ) : new FrontendWorkerBackendBinding(7, \hash('sha256', 'fixture-session'), 'shop.example.test', $now, $now + 1800);
        try {
            try {
                $created = $kind === 'scope' ? $service->createScopeBootstrap($binding)
                    : $service->createBackendBootstrap($binding, true);
                self::assertNotSame('never-ack', $fault, 'unconfirmed commits must not be reported as success');
            } catch (FrontendQueryException $exception) {
                if ($fault !== 'never-ack') { throw $exception; }
                self::assertSame('worker_store_unavailable', $exception->getErrorCode());
                self::assertSame(503, $exception->getHttpStatus());
                self::assertSame(96, $adapter->casCalls, 'bounded retry budget is exhausted');
                self::assertCount(96, $adapter->attemptHashes, 'no retry reuses an already committed credential');
                return;
            }
            self::assertSame(2, $adapter->casCalls);
            self::assertCount(2, $adapter->attemptHashes, 'CAS replay must generate a new credential, not disable collision rejection');
            self::assertSame($adapter->lastCredentialHash, \hash('sha256', $created['bootstrap_id']));
            $bucket = $kind === 'scope' ? 'weline_frontend_worker_scope_bootstraps' : 'weline_frontend_worker_backend_bootstraps';
            $entries = $adapter->getFresh('worker_state.v1')[$bucket];
            self::assertCount($fault === 'no-commit' ? 1 : 2, $entries, 'unknown committed credential retains its original expiry; no fabricated rollback');
            $record = $entries[\hash('sha256', $created['bootstrap_id'])];
            self::assertSame($record['cookie_name'], $created['cookie_name']);
            self::assertSame($record['expires_at'], $created['expires_at']);
            self::assertLessThanOrEqual(120, $created['expires_at'] - $now);
            self::assertLessThanOrEqual($now + 1800, $created['expires_at']);
            try {
                if ($kind === 'scope') {
                    $service->peekScopeBootstrap($created['bootstrap_id'], \hash('sha256', 'wrong-proof'));
                } else {
                    $service->peekBackendBootstrap($created['bootstrap_id'], \str_repeat('X', 43), true);
                }
                self::fail('Wrong proof must still be rejected after an uncertain commit retry.');
            } catch (FrontendQueryException $exception) {
                self::assertSame('auth_error', $exception->getErrorCode());
                self::assertSame(401, $exception->getHttpStatus());
            }
            $readBinding = $kind === 'scope'
                ? $service->peekScopeBootstrap($created['bootstrap_id'], $binding->tokenFingerprint)
                : $service->peekBackendBootstrap($created['bootstrap_id'], $created['cookie_value'], true);
            self::assertSame($binding->digest(), $readBinding->digest());
        } finally {
            $files->clear();
            @\unlink($path . '/state.lock');
            @\rmdir($path . '/state');
            @\rmdir($path);
        }
    }

    public function testExistingIdentityCollisionIsStillRejected(): void
    {
        $state = [];
        $transaction = new \Weline\Framework\Service\Query\Store\ArrayFrontendWorkerCredentialTransaction($state);
        $now = \time();
        $type = \Weline\Framework\Service\Query\Store\FrontendWorkerCredentialType::SCOPE_BOOTSTRAP;
        $transaction->insert($type, 'fixture-identity', null, ['expires_at' => $now + 120], $now, $now + 120);
        $this->expectException(FrontendQueryException::class);
        $this->expectExceptionMessage('Worker credential identity collision.');
        $transaction->insert($type, 'fixture-identity', null, ['expires_at' => $now + 120], $now, $now + 120);
    }

    public function testBootstrapSurvivesTwentyFourTransientCasConflicts(): void
    {
        $path = BP . 'dev/tmp/bootstrap-cas-' . \bin2hex(\random_bytes(8));
        $files = new FileAdapter('state', ['path' => $path]);
        $adapter = new BootstrapCasFaultAdapter($files, $path . '/state.lock', 'contention-24');
        $service = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'test-cas', 600));
        $now = \time();
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test', \hash('sha256', 'fixture-token'), $now, $now + 1800, true,
        );
        try {
            $created = $service->createScopeBootstrap($binding);
            self::assertSame(25, $adapter->casCalls);
            self::assertSame($adapter->lastCredentialHash, \hash('sha256', $created['bootstrap_id']));
        } finally {
            $files->clear();
            @\unlink($path . '/state.lock');
            @\rmdir($path . '/state');
            @\rmdir($path);
        }
    }

    public function testScopeBootstrapUsesNewIdentityAfterAmbiguousFirstWrite(): void
    {
        $path = BP . 'dev/tmp/bootstrap-cas-' . \bin2hex(\random_bytes(8));
        $files = new FileAdapter('state', ['path' => $path]);
        $adapter = new BootstrapCasFaultAdapter($files, $path . '/state.lock', 'unknown-once');
        $service = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'test-cas', 600));
        $now = \time();
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test', \hash('sha256', 'fixture-token'), $now, $now + 1800, true,
        );
        try {
            $created = $service->createScopeBootstrap($binding);
            self::assertSame(2, $adapter->casCalls);
            self::assertCount(2, $adapter->attemptHashes);
            self::assertSame($adapter->lastCredentialHash, \hash('sha256', $created['bootstrap_id']));
            self::assertSame($binding->digest(), $service->peekScopeBootstrap(
                $created['bootstrap_id'], $binding->tokenFingerprint,
            )->digest());
        } finally {
            $files->clear();
            @\unlink($path . '/state.lock');
            @\rmdir($path . '/state');
            @\rmdir($path);
        }
    }

    public function testScopeBootstrapRetriesFirstUnavailableWriteBeforeIssuingCredential(): void
    {
        $path = BP . 'dev/tmp/bootstrap-cas-' . \bin2hex(\random_bytes(8));
        $files = new FileAdapter('state', ['path' => $path]);
        $adapter = new BootstrapCasFaultAdapter($files, $path . '/state.lock', 'unavailable-once');
        $service = new FrontendWorkerSessionService(new AtomicCacheFrontendWorkerStateStore($adapter, 'test-cas', 600));
        $now = \time();
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test', \hash('sha256', 'fixture-token'), $now, $now + 1800, true,
        );
        try {
            $created = $service->createScopeBootstrap($binding);
            self::assertSame(2, $adapter->casCalls);
            self::assertCount(2, $adapter->attemptHashes);
            self::assertSame($adapter->lastCredentialHash, \hash('sha256', $created['bootstrap_id']));
        } finally {
            $files->clear();
            @\unlink($path . '/state.lock');
            @\rmdir($path . '/state');
            @\rmdir($path);
        }
    }
}

/** Lost result model, not a claim that a real ACK packet was captured. */
final class BootstrapCasFaultAdapter implements AtomicCacheAdapterInterface, FreshCacheReadInterface, CacheAdapterHealthInterface
{
    public int $casCalls = 0;
    public array $attemptHashes = [];
    public string $lastCredentialHash = '';
    private bool $available = true;
    public function __construct(private FileAdapter $files, private string $lockPath, private string $fault) {}
    public function get(string $key): mixed { return $this->files->get($key); }
    public function getFresh(string $key): mixed { return $this->files->get($key); }
    public function set(string $key, mixed $value, int $ttl = 0): bool { return $this->files->set($key, $value, $ttl); }
    public function delete(string $key): bool { return $this->files->delete($key); }
    public function clear(): bool { return $this->files->clear(); }
    public function has(string $key): bool { return $this->files->has($key); }
    public function isAvailable(): bool { return $this->available; }
    public function recoverRemoteProbe(): void { $this->available = true; }
    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        $lock = \fopen($this->lockPath, 'c');
        try {
            \flock($lock, LOCK_EX);
            ++$this->casCalls;
            foreach (['weline_frontend_worker_scope_bootstraps', 'weline_frontend_worker_backend_bootstraps'] as $bucket) {
                $added = \array_diff_key($value[$bucket] ?? [], $expected[$bucket] ?? []);
                foreach (\array_keys($added) as $hash) {
                    $this->attemptHashes[$hash] = true;
                    $this->lastCredentialHash = $hash;
                }
            }
            if ($this->getFresh($key) !== $expected) { return false; }
            if ($this->fault === 'contention-24' && $this->casCalls <= 24) { return false; }
            if ($this->fault === 'unavailable-once' && $this->casCalls === 1) {
                $this->available = false;
                return false;
            }
            if ($this->casCalls === 1 && $this->fault === 'no-commit') { return false; }
            if (!$this->set($key, $value, $ttl)) { throw new \RuntimeException('Isolated fixture write failed.'); }
            if ($this->casCalls === 1 && $this->fault === 'unknown-once') {
                throw new AtomicWriteOutcomeUnknownException('reply lost after commit');
            }
            return $this->fault !== 'never-ack' && $this->casCalls !== 1;
        } finally { \flock($lock, LOCK_UN); \fclose($lock); }
    }
}
