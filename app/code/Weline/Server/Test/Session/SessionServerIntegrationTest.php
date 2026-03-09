<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;
use Weline\Server\Session\Client\SessionClient;

/**
 * Session Server 集成测试
 *
 * 测试 Server 和 Client 的端到端通信。
 */
class SessionServerIntegrationTest extends TestCase
{
    private ?SessionServer $server = null;
    private ?SessionClient $client = null;
    private int $testPort = 29970;
    private string $testPersistPath;

    protected function setUp(): void
    {
        $this->testPersistPath = \sys_get_temp_dir() . '/wls_session_integration_' . \getmypid() . '/';
        if (!\is_dir($this->testPersistPath)) {
            \mkdir($this->testPersistPath, 0755, true);
        }

        $this->server = new SessionServer([
            'port' => $this->testPort,
            'max_sessions' => 1000,
            'session_ttl' => 3600,
            'persist_path' => $this->testPersistPath,
        ]);

        if (!$this->server->start('127.0.0.1', $this->testPort)) {
            $this->markTestSkipped('Cannot start Session Server on port ' . $this->testPort);
        }

        $this->client = new SessionClient('127.0.0.1', $this->testPort, [
            'connect_timeout' => 2.0,
            'timeout' => 2.0,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }

        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }

        $persistFile = $this->testPersistPath . 'wls_session_store.dat';
        if (\is_file($persistFile)) {
            @\unlink($persistFile);
        }
        if (\is_dir($this->testPersistPath)) {
            @\rmdir($this->testPersistPath);
        }
    }

    /**
     * 处理 Server 事件
     */
    private function processServer(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->server->tick(10000);
        }
    }

    /**
     * 测试 Ping/Pong
     */
    public function testPing(): void
    {
        $this->processServer();
        $result = $this->client->ping();
        $this->processServer();
        
        $this->assertTrue($result);
    }

    /**
     * 测试设置和获取 Session 数据
     */
    public function testSetAndGet(): void
    {
        $sessionId = 'integration_test_session_1';
        
        $this->processServer();
        $setResult = $this->client->set($sessionId, 'user_id', 12345);
        $this->processServer();
        
        $this->assertTrue($setResult);
        
        $this->processServer();
        $value = $this->client->get($sessionId, 'user_id');
        $this->processServer();
        
        $this->assertEquals(12345, $value);
    }

    /**
     * 测试获取整个 Session
     */
    public function testGetAll(): void
    {
        $sessionId = 'integration_test_session_2';
        
        $this->processServer();
        $this->client->set($sessionId, 'key1', 'value1');
        $this->processServer();
        $this->client->set($sessionId, 'key2', 'value2');
        $this->processServer();
        
        $all = $this->client->getAll($sessionId);
        $this->processServer();
        
        $this->assertIsArray($all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    /**
     * 测试批量设置 Session
     */
    public function testSetAll(): void
    {
        $sessionId = 'integration_test_session_3';
        $data = ['name' => 'test', 'role' => 'admin', 'active' => true];
        
        $this->processServer();
        $result = $this->client->setAll($sessionId, $data);
        $this->processServer();
        
        $this->assertTrue($result);
        
        $all = $this->client->getAll($sessionId);
        $this->processServer();
        
        $this->assertEquals($data, $all);
    }

    /**
     * 测试删除 Session 键
     */
    public function testDelete(): void
    {
        $sessionId = 'integration_test_session_4';
        
        $this->processServer();
        $this->client->set($sessionId, 'key1', 'value1');
        $this->processServer();
        $this->client->set($sessionId, 'key2', 'value2');
        $this->processServer();
        
        $deleteResult = $this->client->delete($sessionId, 'key1');
        $this->processServer();
        
        $this->assertTrue($deleteResult);
        
        $value = $this->client->get($sessionId, 'key1');
        $this->processServer();
        
        $this->assertNull($value);
        $this->assertEquals('value2', $this->client->get($sessionId, 'key2'));
    }

    /**
     * 测试销毁 Session
     */
    public function testDestroy(): void
    {
        $sessionId = 'integration_test_session_5';
        
        $this->processServer();
        $this->client->set($sessionId, 'key', 'value');
        $this->processServer();
        
        $this->assertTrue($this->client->exists($sessionId));
        $this->processServer();
        
        $destroyResult = $this->client->destroy($sessionId);
        $this->processServer();
        
        $this->assertTrue($destroyResult);
        $this->assertFalse($this->client->exists($sessionId));
    }

    /**
     * 测试检查 Session 是否存在
     */
    public function testExists(): void
    {
        $sessionId = 'integration_test_session_6';
        
        $this->processServer();
        $this->assertFalse($this->client->exists($sessionId));
        $this->processServer();
        
        $this->client->set($sessionId, 'key', 'value');
        $this->processServer();
        
        $this->assertTrue($this->client->exists($sessionId));
    }

    /**
     * 测试获取统计信息
     */
    public function testStats(): void
    {
        $this->processServer();
        $this->client->set('stats_test_1', 'key', 'value');
        $this->processServer();
        $this->client->set('stats_test_2', 'key', 'value');
        $this->processServer();
        
        $stats = $this->client->getStats();
        $this->processServer();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('session_count', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['session_count']);
    }

    /**
     * 测试持久化
     */
    public function testPersist(): void
    {
        $this->processServer();
        $this->client->set('persist_test', 'key', 'value');
        $this->processServer();
        
        $result = $this->client->persist();
        $this->processServer();
        
        $this->assertTrue($result);
        
        $persistFile = $this->testPersistPath . 'wls_session_store.dat';
        $this->assertFileExists($persistFile);
    }

    /**
     * 测试 Touch 刷新过期时间
     */
    public function testTouch(): void
    {
        $sessionId = 'integration_test_session_7';
        
        $this->processServer();
        $this->client->set($sessionId, 'key', 'value');
        $this->processServer();
        
        $result = $this->client->touch($sessionId, 7200);
        $this->processServer();
        
        $this->assertTrue($result);
    }

    /**
     * 测试读取触发滑动过期
     */
    public function testSlidingExpirationOnGetAll(): void
    {
        $sessionId = 'integration_test_session_sliding';

        $this->processServer();
        if (!$this->client->set($sessionId, 'key', 'value', 1)) {
            $this->markTestSkipped('Session server write is unavailable in current environment');
        }
        $this->processServer();

        \usleep(700000);
        $all = $this->client->getAll($sessionId);
        $this->processServer();
        $this->assertSame('value', $all['key'] ?? null);

        // 如果读取不续期，第二次读取会在初始 1 秒 TTL 后返回空。
        \usleep(700000);
        $allAfterSliding = $this->client->getAll($sessionId);
        $this->processServer();
        $this->assertSame('value', $allAfterSliding['key'] ?? null);
    }

    /**
     * 测试复杂数据类型
     */
    public function testComplexData(): void
    {
        $sessionId = 'integration_test_session_8';
        $complexData = [
            'user' => [
                'id' => 123,
                'name' => 'Test User',
                'roles' => ['admin', 'editor'],
            ],
            'preferences' => [
                'theme' => 'dark',
                'language' => 'zh_CN',
            ],
            'metadata' => [
                'login_time' => \time(),
                'ip' => '192.168.1.1',
            ],
        ];
        
        $this->processServer();
        $result = $this->client->setAll($sessionId, $complexData);
        $this->processServer();
        
        $this->assertTrue($result);
        
        $retrieved = $this->client->getAll($sessionId);
        $this->processServer();
        
        $this->assertEquals($complexData, $retrieved);
    }
}
