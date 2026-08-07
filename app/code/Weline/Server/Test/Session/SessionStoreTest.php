<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Session\Server\SessionStore;

/**
 * SessionStore 内存存储测试
 */
class SessionStoreTest extends TestCase
{
    private SessionStore $store;
    private string $testPersistPath;

    protected function setUp(): void
    {
        $this->testPersistPath = \sys_get_temp_dir() . '/wls_session_test_' . \getmypid() . '/';
        if (!\is_dir($this->testPersistPath)) {
            \mkdir($this->testPersistPath, 0755, true);
        }
        
        $this->store = new SessionStore([
            'max_sessions' => 100,
            'session_ttl' => 3600,
            'persist_interval' => 60,
            'persist_on_writes' => 10,
            'persist_path' => $this->testPersistPath,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removePath($this->testPersistPath);
    }

    /**
     * 测试设置和获取单个值
     */
    public function testSetAndGet(): void
    {
        $sessionId = 'test_session_1';
        
        $this->assertTrue($this->store->set($sessionId, 'user_id', 123));
        $this->assertEquals(123, $this->store->get($sessionId, 'user_id'));
        
        $this->assertTrue($this->store->set($sessionId, 'username', 'test_user'));
        $this->assertEquals('test_user', $this->store->get($sessionId, 'username'));
    }

    /**
     * 测试获取不存在的 Session
     */
    public function testGetNonExistent(): void
    {
        $this->assertNull($this->store->get('nonexistent', 'key'));
        $this->assertEquals([], $this->store->get('nonexistent'));
    }

    /**
     * 测试获取整个 Session
     */
    public function testGetAll(): void
    {
        $sessionId = 'test_session_2';
        
        $this->store->set($sessionId, 'key1', 'value1');
        $this->store->set($sessionId, 'key2', 'value2');
        
        $all = $this->store->getAll($sessionId);
        $this->assertIsArray($all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    /**
     * 测试批量设置 Session
     */
    public function testSetAll(): void
    {
        $sessionId = 'test_session_3';
        $data = ['user_id' => 456, 'role' => 'admin', 'active' => true];
        
        $this->assertTrue($this->store->setAll($sessionId, $data));
        
        $all = $this->store->getAll($sessionId);
        $this->assertEquals($data, $all);
    }

    /**
     * 测试删除 Session 键
     */
    public function testDelete(): void
    {
        $sessionId = 'test_session_4';
        
        $this->store->set($sessionId, 'key1', 'value1');
        $this->store->set($sessionId, 'key2', 'value2');
        
        $this->assertTrue($this->store->delete($sessionId, 'key1'));
        $this->assertNull($this->store->get($sessionId, 'key1'));
        $this->assertEquals('value2', $this->store->get($sessionId, 'key2'));
        
        $this->assertFalse($this->store->delete($sessionId, 'nonexistent'));
    }

    /**
     * 测试销毁 Session
     */
    public function testDestroy(): void
    {
        $sessionId = 'test_session_5';
        
        $this->store->set($sessionId, 'key', 'value');
        $this->assertTrue($this->store->exists($sessionId));
        
        $this->assertTrue($this->store->destroy($sessionId));
        $this->assertFalse($this->store->exists($sessionId));
        $this->assertEquals([], $this->store->getAll($sessionId));
    }

    /**
     * 测试检查 Session 是否存在
     */
    public function testExists(): void
    {
        $sessionId = 'test_session_6';
        
        $this->assertFalse($this->store->exists($sessionId));
        
        $this->store->set($sessionId, 'key', 'value');
        $this->assertTrue($this->store->exists($sessionId));
    }

    /**
     * 测试刷新 Session 过期时间
     */
    public function testTouch(): void
    {
        $sessionId = 'test_session_7';
        
        $this->store->set($sessionId, 'key', 'value');
        $this->assertTrue($this->store->touch($sessionId, 7200));
        $this->assertTrue($this->store->exists($sessionId));
        
        $this->assertFalse($this->store->touch('nonexistent'));
    }

    public function testExistsKey(): void
    {
        $sessionId = 'test_session_exists_key';
        $this->store->set($sessionId, 'present', 'value');

        $this->assertTrue($this->store->existsKey($sessionId, 'present'));
        $this->assertFalse($this->store->existsKey($sessionId, 'missing'));
        $this->assertFalse($this->store->existsKey('missing_session', 'present'));
    }

    public function testMgetReturnsAllRequestedKeys(): void
    {
        $sessionId = 'test_session_mget';
        $this->store->set($sessionId, 'key1', 'value1');
        $this->store->set($sessionId, 'key2', 'value2');

        $result = $this->store->mget($sessionId, ['key1', 'key2', 'missing']);

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
            'missing' => null,
        ], $result);
    }

    public function testMsetWritesMultipleKeysOnce(): void
    {
        $sessionId = 'test_session_mset';
        $this->assertTrue($this->store->mset($sessionId, [
            'user_id' => 100,
            'role' => 'admin',
            'enabled' => true,
        ], 7200));

        $this->assertSame(100, $this->store->get($sessionId, 'user_id'));
        $this->assertSame('admin', $this->store->get($sessionId, 'role'));
        $this->assertTrue($this->store->get($sessionId, 'enabled'));
    }

    /**
     * 测试读取触发滑动过期
     */
    public function testSlidingExpirationOnRead(): void
    {
        $store = new SessionStore([
            'max_sessions' => 100,
            'session_ttl' => 1,
            'persist_path' => $this->testPersistPath,
        ]);
        $sessionId = 'test_session_sliding_read';

        $store->set($sessionId, 'key', 'value', 1);

        \usleep(700000);
        $this->assertSame('value', $store->get($sessionId, 'key'));

        // 第一次读取会刷新 TTL；若无滑动过期，这里将返回 null。
        \usleep(700000);
        $this->assertSame('value', $store->get($sessionId, 'key'));
    }

    /**
     * 测试垃圾回收
     */
    public function testGc(): void
    {
        $store = new SessionStore([
            'max_sessions' => 100,
            'session_ttl' => 1,
            'persist_path' => $this->testPersistPath,
        ]);
        
        $store->set('session1', 'key', 'value', 1);
        $store->set('session2', 'key', 'value', 3600);
        
        \Weline\Framework\Runtime\SchedulerSystem::sleep(2);
        
        $cleaned = $store->gc(0);
        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($store->exists('session1'));
        $this->assertTrue($store->exists('session2'));
    }

    /**
     * 测试统计信息
     */
    public function testGetStats(): void
    {
        $this->store->set('session1', 'key', 'value');
        $this->store->set('session2', 'key', 'value');
        
        $stats = $this->store->getStats();
        
        $this->assertArrayHasKey('session_count', $stats);
        $this->assertArrayHasKey('max_sessions', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertEquals(2, $stats['session_count']);
        $this->assertEquals(100, $stats['max_sessions']);
    }

    public function testMemoryWatermarkEvictsBeforePhpMemoryLimit(): void
    {
        $baseline = \memory_get_usage(false);
        $store = new SessionStore([
            'max_sessions' => 100,
            'persist_enabled' => false,
            'persist_path' => $this->testPersistPath,
            'memory_high_watermark_bytes' => $baseline + 131072,
            'memory_low_watermark_bytes' => $baseline + 65536,
        ]);

        $store->set('pressure-session', 'payload', \str_repeat('x', 1048576));
        $stats = $store->getStats();

        $this->assertGreaterThan(0, $stats['memory_pressure_eviction_count']);
        $this->assertSame(0, $stats['session_count']);
    }

    /**
     * 测试持久化和加载
     */
    public function testPersistAndLoad(): void
    {
        $sessionId = 'persist_test_session';
        $this->store->set($sessionId, 'user_id', 789);
        $this->store->set($sessionId, 'name', 'persist_user');
        
        $this->assertTrue($this->store->forcePersist());
        
        $newStore = new SessionStore([
            'persist_path' => $this->testPersistPath,
        ]);
        
        $loaded = $newStore->loadFromFile();
        $this->assertTrue($loaded);
        $this->assertEquals(789, $newStore->get($sessionId, 'user_id'));
        $this->assertEquals('persist_user', $newStore->get($sessionId, 'name'));
    }

    public function testSecondPersistenceKeepsUnchangedSessionsAcrossRestart(): void
    {
        $this->store->set('changed-session', 'version', 1);
        $this->store->set('unchanged-session-a', 'value', 'alpha');
        $this->store->set('unchanged-session-b', 'value', 'beta');
        $this->assertTrue($this->store->forcePersist());

        $this->store->set('changed-session', 'version', 2);
        $this->assertTrue($this->store->forcePersist());

        $restarted = new SessionStore([
            'persist_path' => $this->testPersistPath,
        ]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame(2, $restarted->get('changed-session', 'version'));
        $this->assertSame('alpha', $restarted->get('unchanged-session-a', 'value'));
        $this->assertSame('beta', $restarted->get('unchanged-session-b', 'value'));
    }

    public function testLoadCollectsLegacyCrashStagingAfterValidSnapshot(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $target = $this->testPersistPath . 'wls_session_store.dat';
        $legacyStaging = $target . '.tmp.1234.6a749c433c8aaf51160152';
        $this->assertNotFalse(\file_put_contents($legacyStaging, 'interrupted'));

        $restarted = new SessionStore([
            'persist_path' => $this->testPersistPath,
        ]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame('committed', $restarted->get('committed-session', 'value'));
        $this->assertFileDoesNotExist($legacyStaging);
    }

    public function testDeletedSessionIsNotResurrectedByLaterSnapshot(): void
    {
        $this->store->set('kept-session', 'value', 'kept');
        $this->store->set('deleted-session', 'value', 'deleted');
        $this->assertTrue($this->store->forcePersist());
        $this->assertTrue($this->store->destroy('deleted-session'));
        $this->assertTrue($this->store->forcePersist());

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame('kept', $restarted->get('kept-session', 'value'));
        $this->assertFalse($restarted->exists('deleted-session'));
    }

    public function testPersistenceRefusesOversizedSnapshotWithoutReplacingCommittedTarget(): void
    {
        $store = new SessionStore([
            'persist_path' => $this->testPersistPath,
            'persist_max_bytes' => 1024,
        ]);
        $store->set('baseline-session', 'value', 'baseline');
        $this->assertTrue($store->forcePersist());
        $target = $this->persistTarget();
        $baseline = \file_get_contents($target);
        $this->assertIsString($baseline);

        $store->set('oversized-session', 'value', \str_repeat('x', 4096));
        $this->assertFalse($store->forcePersist());
        $this->assertSame($baseline, \file_get_contents($target));
    }

    public function testLoadRestoresValidLegacyFullSnapshotWhenTargetIsMissing(): void
    {
        $target = $this->persistTarget();
        $legacyStaging = $target . '.tmp.4321.6a749c433c8aa151160153';
        $this->assertNotFalse(\file_put_contents(
            $legacyStaging,
            $this->snapshotRaw([
                'legacy-session' => $this->snapshotEntry(['value' => 'restored']),
            ]),
        ));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame('restored', $restarted->get('legacy-session', 'value'));
        $this->assertFileExists($target);
        $this->assertFileDoesNotExist($legacyStaging);
    }

    public function testLoadPreservesLegacyIncrementalStagingWhenTargetIsMissing(): void
    {
        $legacyStaging = $this->persistTarget() . '.tmp.4321.6a749c433c8aa151160154';
        $this->assertNotFalse(\file_put_contents($legacyStaging, \serialize([
            'incremental' => true,
            'data' => [
                'partial-session' => $this->snapshotEntry(['value' => 'partial']),
            ],
        ])));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($legacyStaging);
        $this->assertFileDoesNotExist($this->persistTarget());
    }

    public function testLoadRestoresAtomicBackupWhenTargetIsMissing(): void
    {
        $backup = $this->persistTarget() . '.wls-backup-0123456789abcdef';
        $this->assertNotFalse(\file_put_contents(
            $backup,
            $this->snapshotRaw([
                'backup-session' => $this->snapshotEntry(['value' => 'backup']),
            ]),
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(\chmod($backup, 0600));
        }

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame('backup', $restarted->get('backup-session', 'value'));
        $this->assertFileExists($this->persistTarget());
        $this->assertFileDoesNotExist($backup);
    }

    public function testLoadDiscardsAtomicStagingWhenNoTargetWasCommitted(): void
    {
        $staging = $this->persistTarget() . '.tmp-0123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($staging, 'uncommitted'));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileDoesNotExist($staging);
        $this->assertFileDoesNotExist($this->persistTarget());
    }

    public function testLoadCollectsAtomicBackupAndStagingAfterValidSnapshot(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $backup = $this->persistTarget() . '.wls-backup-1123456789abcdef';
        $staging = $this->persistTarget() . '.tmp-5123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($backup, 'retained-backup'));
        $this->assertNotFalse(\file_put_contents($staging, 'retained-staging'));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertSame('committed', $restarted->get('committed-session', 'value'));
        $this->assertFileDoesNotExist($backup);
        $this->assertFileDoesNotExist($staging);
    }

    public function testCorruptTargetPreservesAllRecoveryEvidence(): void
    {
        $target = $this->persistTarget();
        $legacy = $target . '.tmp.5432.6a749c433c8aa151160155';
        $atomic = $target . '.tmp-1123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($target, 'corrupt'));
        $this->assertNotFalse(\file_put_contents($legacy, 'legacy'));
        $this->assertNotFalse(\file_put_contents($atomic, 'atomic'));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($target);
        $this->assertFileExists($legacy);
        $this->assertFileExists($atomic);
    }

    public function testMalformedReservedArtifactFailsClosedWithoutDeletingEvidence(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $malformed = $this->persistTarget() . '.tmp-not-a-token';
        $this->assertNotFalse(\file_put_contents($malformed, 'reserved'));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($malformed);
        $this->assertFileExists($this->persistTarget());
    }

    public function testRecoveryCaseAliasFailsClosedWithoutDeletingEvidence(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $caseAlias = $this->persistTarget() . '.TMP-0123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($caseAlias, 'reserved'));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($caseAlias);
    }

    public function testRecoverySymlinkFailsClosedWithoutDeletingEvidence(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation is not reliably available on Windows CI.');
        }
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $peer = $this->testPersistPath . 'symlink-peer';
        $artifact = $this->persistTarget() . '.tmp-2123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($peer, 'peer'));
        $this->assertTrue(\symlink($peer, $artifact));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertTrue(\is_link($artifact));
        $this->assertFileExists($peer);
    }

    public function testRecoveryHardLinkFailsClosedWithoutDeletingEvidence(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $peer = $this->testPersistPath . 'hardlink-peer';
        $artifact = $this->persistTarget() . '.tmp-3123456789abcdef01234567';
        $this->assertNotFalse(\file_put_contents($peer, 'peer'));
        if (!@\link($peer, $artifact)) {
            $this->markTestSkipped('Hard-link creation is unavailable on this filesystem.');
        }

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($artifact);
        $this->assertFileExists($peer);
    }

    public function testRecoverySpecialFileFailsClosedWithoutDeletingEvidence(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $artifact = $this->persistTarget() . '.tmp-4123456789abcdef01234567';
        $this->assertTrue(\mkdir($artifact));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertDirectoryExists($artifact);
    }

    public function testRecoveryArtifactQuotaFailsBeforeAnyDeletion(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $artifacts = [];
        for ($index = 0; $index < 9; ++$index) {
            $artifact = $this->persistTarget()
                . '.tmp.600'
                . $index
                . '.6a749c433c8aa1511601'
                . \str_pad((string)$index, 2, '0', STR_PAD_LEFT);
            $this->assertNotFalse(\file_put_contents($artifact, 'artifact-' . $index));
            $artifacts[] = $artifact;
        }

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        foreach ($artifacts as $artifact) {
            $this->assertFileExists($artifact);
        }
    }

    public function testRecoveryDirectoryEntryQuotaFailsBeforeAnyDeletion(): void
    {
        $this->store->set('committed-session', 'value', 'committed');
        $this->assertTrue($this->store->forcePersist());
        $artifact = $this->persistTarget() . '.tmp.7123.6a749c433c8aa151160156';
        $this->assertNotFalse(\file_put_contents($artifact, 'artifact'));
        for ($index = 0; $index < 40; ++$index) {
            $this->assertNotFalse(\file_put_contents(
                $this->testPersistPath . 'unrelated-' . $index,
                '',
            ));
        }

        $restarted = new SessionStore([
            'persist_path' => $this->testPersistPath,
            'persist_recovery_max_directory_entries' => 32,
        ]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFileExists($artifact);
    }

    public function testPersistenceLockDeadlineKeepsCommittedSnapshotUnchanged(): void
    {
        $store = new SessionStore([
            'persist_path' => $this->testPersistPath,
            'persist_lock_timeout' => 0.05,
        ]);
        $store->set('baseline-session', 'value', 'baseline');
        $this->assertTrue($store->forcePersist());
        $target = $this->persistTarget();
        $baseline = \file_get_contents($target);
        $this->assertIsString($baseline);
        $lockPath = $target . '.persist.lock';
        $lock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            1.0,
            static fn (): array => ['purpose' => 'session-store-test-holder'],
        );
        $this->assertIsResource($lock);

        try {
            $store->set('contended-session', 'value', 'not-published');
            $startedAt = \hrtime(true);
            $this->assertFalse($store->forcePersist());
            $elapsedSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
            $this->assertLessThan(0.5, $elapsedSeconds);
            $this->assertSame($baseline, \file_get_contents($target));
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    public function testLoadRejectsStructurallyInvalidSnapshotWithoutPartialState(): void
    {
        $this->assertNotFalse(\file_put_contents($this->persistTarget(), \serialize([
            'valid-session' => $this->snapshotEntry(['value' => 'must-not-load']),
            'invalid-session' => ['data' => 'not-an-array', 'expire' => 0, 'atime' => \time()],
        ])));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFalse($restarted->exists('valid-session'));
        $this->assertFalse($restarted->exists('invalid-session'));
    }

    public function testLoadRejectsSnapshotAboveConfiguredSessionLimit(): void
    {
        $sessions = [];
        for ($index = 0; $index < 3; ++$index) {
            $sessions['session-' . $index] = $this->snapshotEntry(['index' => $index]);
        }
        $this->assertNotFalse(\file_put_contents($this->persistTarget(), $this->snapshotRaw($sessions)));

        $restarted = new SessionStore([
            'persist_path' => $this->testPersistPath,
            'max_sessions' => 2,
        ]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertSame([], $restarted->getAllSessionIds());
    }

    public function testLoadRejectsLinkedTarget(): void
    {
        $target = $this->persistTarget();
        $this->assertNotFalse(\file_put_contents(
            $target,
            $this->snapshotRaw(['linked-session' => $this->snapshotEntry(['value' => 'linked'])]),
        ));
        $peer = $this->testPersistPath . 'target-hardlink-peer';
        if (!@\link($target, $peer)) {
            $this->markTestSkipped('Hard-link creation is unavailable on this filesystem.');
        }

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFalse($restarted->exists('linked-session'));
        $this->assertFileExists($target);
        $this->assertFileExists($peer);
    }

    public function testLoadRejectsSymlinkTargetWithoutFollowingIt(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation is not reliably available on Windows CI.');
        }
        $target = $this->persistTarget();
        $peer = $this->testPersistPath . 'target-symlink-peer';
        $this->assertNotFalse(\file_put_contents(
            $peer,
            $this->snapshotRaw([
                'symlink-session' => $this->snapshotEntry(['value' => 'must-not-load']),
            ]),
        ));
        $this->assertTrue(\symlink($peer, $target));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFalse($restarted->exists('symlink-session'));
        $this->assertTrue(\is_link($target));
        $this->assertFileExists($peer);
    }

    public function testPersistenceRejectsSymlinkDirectoryWithoutWritingThroughIt(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation is not reliably available on Windows CI.');
        }
        $realDirectory = $this->testPersistPath . 'real-persist-directory';
        $linkedDirectory = $this->testPersistPath . 'linked-persist-directory';
        $this->assertTrue(\mkdir($realDirectory));
        $this->assertTrue(\symlink($realDirectory, $linkedDirectory));
        $store = new SessionStore([
            'persist_path' => $linkedDirectory,
            'persist_lock_timeout' => 0.05,
        ]);
        $store->set('blocked-session', 'value', 'blocked');

        $this->assertFalse($store->forcePersist());
        $this->assertFileDoesNotExist($realDirectory . '/wls_session_store.dat');
        $this->assertFileDoesNotExist($realDirectory . '/wls_session_store.dat.persist.lock');
    }

    public function testLoadDoesNotReviveExpiredSnapshotEntry(): void
    {
        $this->assertNotFalse(\file_put_contents($this->persistTarget(), $this->snapshotRaw([
            'expired-session' => [
                'data' => ['value' => 'expired'],
                'expire' => \time() - 10,
                'atime' => \time() - 20,
            ],
            'live-session' => $this->snapshotEntry(['value' => 'live']),
        ])));

        $restarted = new SessionStore(['persist_path' => $this->testPersistPath]);
        $this->assertTrue($restarted->loadFromFile());
        $this->assertFalse($restarted->exists('expired-session'));
        $this->assertSame('live', $restarted->get('live-session', 'value'));
    }

    public function testPersistUsesCustomFileName(): void
    {
        $store = new SessionStore([
            'persist_path' => $this->testPersistPath,
            'persist_file_name' => 'wls_memory_store.dat',
        ]);

        $store->set('custom_file_session', 'key', 'value');
        $this->assertTrue($store->forcePersist());
        $this->assertFileExists($this->testPersistPath . 'wls_memory_store.dat');
    }

    public function testPersistRecreatesMissingDirectory(): void
    {
        $missingDir = $this->testPersistPath . 'missing-dir/';
        $store = new SessionStore([
            'persist_path' => $missingDir,
            'persist_file_name' => 'wls_session_store.dat',
        ]);

        if (\is_dir($missingDir)) {
            @\rmdir($missingDir);
        }

        $store->set('missing_dir_session', 'key', 'value');
        $this->assertTrue($store->forcePersist());
        $this->assertFileExists($missingDir . 'wls_session_store.dat');
    }

    public function testLegacySnapshotMigratesIntoDedicatedStateDirectoryOnce(): void
    {
        $stateDirectory = $this->testPersistPath . '.wls-state/';
        $legacyTarget = $this->persistTarget();
        $this->assertNotFalse(\file_put_contents($legacyTarget, $this->snapshotRaw([
            'legacy-layout-session' => $this->snapshotEntry(['value' => 'migrated']),
        ])));

        $store = new SessionStore([
            'persist_path' => $stateDirectory,
            'legacy_persist_path' => $this->testPersistPath,
        ]);
        $this->assertTrue($store->loadFromFile());
        $this->assertSame('migrated', $store->get('legacy-layout-session', 'value'));
        $this->assertFileExists($stateDirectory . 'wls_session_store.dat');
        $this->assertFileDoesNotExist($legacyTarget);
        $markers = \glob($stateDirectory . '.legacy-retired-*') ?: [];
        $this->assertCount(1, $markers);
        $this->assertDirectoryExists($markers[0]);
    }

    public function testRetirementMarkerPreventsStaleLegacySnapshotResurrection(): void
    {
        $stateDirectory = $this->testPersistPath . '.wls-state/';
        $legacyTarget = $this->persistTarget();
        $this->assertNotFalse(\file_put_contents($legacyTarget, $this->snapshotRaw([
            'legacy-layout-session' => $this->snapshotEntry(['value' => 'initial']),
        ])));
        $config = [
            'persist_path' => $stateDirectory,
            'legacy_persist_path' => $this->testPersistPath,
        ];
        $store = new SessionStore($config);
        $this->assertTrue($store->loadFromFile());
        $store->set('legacy-layout-session', 'value', 'new-generation');
        $this->assertTrue($store->forcePersist());
        $this->assertTrue(\unlink($stateDirectory . 'wls_session_store.dat'));
        $this->assertNotFalse(\file_put_contents($legacyTarget, $this->snapshotRaw([
            'legacy-layout-session' => $this->snapshotEntry(['value' => 'stale']),
        ])));

        $restarted = new SessionStore($config);
        $this->assertFalse($restarted->loadFromFile());
        $this->assertFalse($restarted->exists('legacy-layout-session'));
        $this->assertFileExists($legacyTarget);
    }

    public function testDedicatedStateDirectoryIsNotBlockedByHighCardinalityLegacyDirectory(): void
    {
        for ($index = 0; $index < 40; ++$index) {
            $this->assertNotFalse(\file_put_contents(
                $this->testPersistPath . 'php-session-' . $index,
                'session',
            ));
        }
        $stateDirectory = $this->testPersistPath . '.wls-state/';
        $store = new SessionStore([
            'persist_path' => $stateDirectory,
            'legacy_persist_path' => $this->testPersistPath,
            'persist_recovery_max_directory_entries' => 16,
        ]);
        $store->set('low-cardinality-state', 'value', 'safe');

        $this->assertTrue($store->forcePersist());
        $this->assertFileExists($stateDirectory . 'wls_session_store.dat');
    }

    public function testRuntimeEntrypointSelectsDedicatedSessionStateDirectory(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/bin/session_server.php');

        $this->assertStringContainsString("'legacy_persist_path'", $source);
        $this->assertStringContainsString("'.wls-state'", $source);
    }

    /**
     * 测试 LRU 淘汰
     */
    public function testLruEviction(): void
    {
        $smallStore = new SessionStore([
            'max_sessions' => 5,
            'session_ttl' => 3600,
            'persist_path' => $this->testPersistPath,
        ]);
        
        for ($i = 1; $i <= 5; $i++) {
            $smallStore->set("session{$i}", 'key', "value{$i}");
        }
        
        $smallStore->set('session6', 'key', 'value6');
        
        $sessionIds = $smallStore->getAllSessionIds();
        $this->assertLessThanOrEqual(5, \count($sessionIds));
        $this->assertContains('session6', $sessionIds);
    }

    private function removePath(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            $this->removePath($entry->getPathname());
        }
        @\rmdir($path);
    }

    private function persistTarget(): string
    {
        return $this->testPersistPath . 'wls_session_store.dat';
    }

    /** @param array<string,array{data:array<array-key,mixed>,expire:int,atime:int}> $sessions */
    private function snapshotRaw(array $sessions): string
    {
        return \serialize($sessions);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{data:array<string,mixed>,expire:int,atime:int}
     */
    private function snapshotEntry(array $data): array
    {
        return [
            'data' => $data,
            'expire' => \time() + 3600,
            'atime' => \time(),
        ];
    }
}
