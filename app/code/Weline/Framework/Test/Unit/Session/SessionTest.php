<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Storage\SessionStorageInterface;
use Weline\Framework\Session\Strategy\FpmStrategy;
use Weline\Framework\Session\Strategy\SessionStrategyInterface;
use Weline\Framework\Session\Strategy\WlsStrategy;

/**
 * Session 单元测试
 *
 * 测试 Session 类的数据存取和生命周期功能。
 */
class SessionTest extends TestCase
{
    private Session $session;
    private SessionStorageInterface $storage;
    private SessionStrategyInterface $strategy;
    private string $testSessionId;

    protected function setUp(): void
    {
        $this->storage = new FileStorage([
            'path' => 'var/test_session/',
            'lifetime' => 3600,
        ]);
        
        $this->strategy = new FpmStrategy($this->storage, [
            'lifetime' => 3600,
        ]);
        
        $this->session = new Session($this->storage, $this->strategy, 3600);
        $this->testSessionId = 'test_session_' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        Session::flushRequestSessions();
        Session::resetRequestState();
        $this->storage->destroy($this->testSessionId);
    }

    public function testSessionImplementsInterface(): void
    {
        $this->assertInstanceOf(SessionInterface::class, $this->session);
    }

    public function testStartSession(): void
    {
        $this->assertFalse($this->session->isStarted());
        
        $this->session->start($this->testSessionId);
        
        $this->assertTrue($this->session->isStarted());
        $this->assertIsString($this->session->getId());
    }

    public function testSetAndGetData(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->session->set('test_key', 'test_value');
        
        $this->assertEquals('test_value', $this->session->get('test_key'));
    }

    public function testHasData(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->assertFalse($this->session->has('nonexistent'));
        
        $this->session->set('exists', true);
        
        $this->assertTrue($this->session->has('exists'));
    }

    public function testDeleteData(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->session->set('to_delete', 'value');
        $this->assertTrue($this->session->has('to_delete'));
        
        $this->session->delete('to_delete');
        $this->assertFalse($this->session->has('to_delete'));
    }

    public function testGetAllData(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->session->set('key1', 'value1');
        $this->session->set('key2', 'value2');
        
        $all = $this->session->all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
    }

    public function testClearData(): void
    {
        $this->session->start($this->testSessionId);
        
        $this->session->set('key1', 'value1');
        $this->session->set('key2', 'value2');
        
        $this->session->clear();
        
        $this->assertEmpty($this->session->all());
    }

    public function testAutoStartOnGet(): void
    {
        $this->assertFalse($this->session->isStarted());
        
        $value = $this->session->get('any_key');
        
        $this->assertTrue($this->session->isStarted());
        $this->assertNull($value);
    }

    public function testComplexDataTypes(): void
    {
        $this->session->start($this->testSessionId);
        
        $arrayData = ['nested' => ['data' => [1, 2, 3]]];
        $this->session->set('array', $arrayData);
        
        $objectData = new \stdClass();
        $objectData->prop = 'value';
        $this->session->set('object', $objectData);
        
        $this->assertEquals($arrayData, $this->session->get('array'));
    }

    public function testReset(): void
    {
        $this->session->start($this->testSessionId);
        $this->session->set('key', 'value');
        
        $this->session->reset();
        
        $this->assertFalse($this->session->isStarted());
        $this->assertEquals('', $this->session->getId());
    }

    public function testWlsSessionDefersPersistUntilSave(): void
    {
        $storage = $this->createMock(SessionStorageInterface::class);
        $storage->method('read')->willReturn([]);
        $storage->expects($this->once())
            ->method('write')
            ->with('wls_session', ['foo' => 'bar'], 3600)
            ->willReturn(true);

        $session = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
        $session->start('wls_session');
        $session->set('foo', 'bar');
        $session->save();
    }

    public function testFlushRequestSessionsPersistsDirtyWlsSession(): void
    {
        $storage = $this->createMock(SessionStorageInterface::class);
        $storage->method('read')->willReturn([]);
        $storage->expects($this->once())
            ->method('write')
            ->with('flush_session', ['alpha' => 'beta'], 3600)
            ->willReturn(true);

        $session = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
        $session->start('flush_session');
        $session->set('alpha', 'beta');

        Session::flushRequestSessions();
    }

    public function testConcurrentWlsSessionSavesMergeIndependentKeys(): void
    {
        $storage = $this->createInMemoryStorage([
            'shared_session' => ['base' => 'v1'],
        ]);

        $sessionA = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
        $sessionB = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);

        $sessionA->start('shared_session');
        $sessionB->start('shared_session');

        $sessionA->set('WF_BACKEND_USER', 'admin');
        $sessionB->set('frontend_notice', 'hello');

        $sessionA->save();
        $sessionB->save();

        self::assertSame([
            'base' => 'v1',
            'WF_BACKEND_USER' => 'admin',
            'frontend_notice' => 'hello',
        ], $storage->read('shared_session'));
    }

    public function testConcurrentWlsSessionDeletePreservesUnrelatedWrites(): void
    {
        $storage = $this->createInMemoryStorage([
            'shared_session' => [
                'WF_BACKEND_USER' => 'admin',
                'backend_acl_role_id' => 3,
                'base' => 'v1',
            ],
        ]);

        $sessionA = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
        $sessionB = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);

        $sessionA->start('shared_session');
        $sessionB->start('shared_session');

        $sessionA->delete('backend_acl_role_id');
        $sessionB->set('frontend_notice', 'hello');

        $sessionB->save();
        $sessionA->save();

        self::assertSame([
            'WF_BACKEND_USER' => 'admin',
            'base' => 'v1',
            'frontend_notice' => 'hello',
        ], $storage->read('shared_session'));
    }

    private function createInMemoryStorage(array $seed = []): SessionStorageInterface
    {
        return new class($seed) implements SessionStorageInterface {
            private array $store;

            public function __construct(array $seed)
            {
                $this->store = $seed;
            }

            public function read(string $sessionId): array
            {
                return $this->store[$sessionId] ?? [];
            }

            public function write(string $sessionId, array $data, int $ttl): bool
            {
                $this->store[$sessionId] = $data;
                return true;
            }

            public function destroy(string $sessionId): bool
            {
                unset($this->store[$sessionId]);
                return true;
            }

            public function exists(string $sessionId): bool
            {
                return \array_key_exists($sessionId, $this->store);
            }

            public function touch(string $sessionId, int $ttl): bool
            {
                return $this->exists($sessionId);
            }

            public function gc(int $maxLifetime): int
            {
                return 0;
            }

            public function getConfig(): array
            {
                return [];
            }

            public function list(array $options = []): array
            {
                $result = [];
                foreach ($this->store as $sessionId => $data) {
                    $result[] = ['session_id' => $sessionId, 'data' => $data];
                }
                return $result;
            }
        };
    }
}
