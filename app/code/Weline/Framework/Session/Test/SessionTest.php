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
        $this->assertEquals($this->testSessionId, $this->session->getId());
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
}
