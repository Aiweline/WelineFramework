<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
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
        $persistFile = $this->testPersistPath . 'wls_session_store.dat';
        if (\is_file($persistFile)) {
            @\unlink($persistFile);
        }
        if (\is_dir($this->testPersistPath)) {
            @\rmdir($this->testPersistPath);
        }
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
        
        \sleep(2);
        
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
}
