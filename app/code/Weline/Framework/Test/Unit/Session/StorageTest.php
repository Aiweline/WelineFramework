<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Storage\SessionStorageInterface;

/**
 * Storage 单元测试
 *
 * 测试各种存储后端的功能。
 */
class StorageTest extends TestCase
{
    private FileStorage $storage;
    private string $testPath;

    protected function setUp(): void
    {
        $this->testPath = 'var/test_storage/';
        $this->storage = new FileStorage([
            'path' => $this->testPath,
            'lifetime' => 3600,
        ]);
    }

    protected function tearDown(): void
    {
        $path = BP . \str_replace('/', DS, $this->testPath);
        if (\is_dir($path)) {
            $files = \glob($path . '*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_file($file)) {
                        @\unlink($file);
                    }
                }
            }
        }
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(SessionStorageInterface::class, $this->storage);
    }

    public function testReadNonexistent(): void
    {
        $data = $this->storage->read('nonexistent_session_id');
        
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function testWriteAndRead(): void
    {
        $sessionId = 'test_session_' . \bin2hex(\random_bytes(8));
        $data = ['user_id' => 1, 'username' => 'admin'];
        
        $result = $this->storage->write($sessionId, $data, 3600);
        
        $this->assertTrue($result);
        
        $readData = $this->storage->read($sessionId);
        
        $this->assertEquals($data, $readData);
        
        $this->storage->destroy($sessionId);
    }

    public function testDestroy(): void
    {
        $sessionId = 'test_destroy_' . \bin2hex(\random_bytes(8));
        $this->storage->write($sessionId, ['test' => 'data'], 3600);
        
        $this->assertTrue($this->storage->exists($sessionId));
        
        $result = $this->storage->destroy($sessionId);
        
        $this->assertTrue($result);
        $this->assertFalse($this->storage->exists($sessionId));
    }

    public function testExists(): void
    {
        $sessionId = 'test_exists_' . \bin2hex(\random_bytes(8));
        
        $this->assertFalse($this->storage->exists($sessionId));
        
        $this->storage->write($sessionId, ['test' => 'data'], 3600);
        
        $this->assertTrue($this->storage->exists($sessionId));
        
        $this->storage->destroy($sessionId);
    }

    public function testTouch(): void
    {
        $sessionId = 'test_touch_' . \bin2hex(\random_bytes(8));
        $this->storage->write($sessionId, ['test' => 'data'], 3600);
        
        $result = $this->storage->touch($sessionId, 7200);
        
        $this->assertTrue($result);
        
        $this->storage->destroy($sessionId);
    }

    public function testTouchNonexistent(): void
    {
        $result = $this->storage->touch('nonexistent_id', 3600);
        
        $this->assertFalse($result);
    }

    public function testGc(): void
    {
        $sessionId = 'test_gc_' . \bin2hex(\random_bytes(8));
        $this->storage->write($sessionId, ['test' => 'data'], 3600);
        
        $cleaned = $this->storage->gc(3600);
        
        $this->assertIsInt($cleaned);
        $this->assertGreaterThanOrEqual(0, $cleaned);
        
        $this->storage->destroy($sessionId);
    }

    public function testGcSkipsInfrastructureFiles(): void
    {
        $dir = BP . \str_replace('/', DS, $this->testPath);
        $oldTs = \time() - 7200;
        $sessionFile = $dir . 'a1b2c3d4e5f6789012345678abcdef01';
        $tokenFile = $dir . 'session_server.token';
        $datFile = $dir . 'wls_session_store.dat';
        \file_put_contents($sessionFile, 'x');
        \file_put_contents($tokenFile, 'token');
        \file_put_contents($datFile, 'dat');
        \touch($sessionFile, $oldTs);
        \touch($tokenFile, $oldTs);
        \touch($datFile, $oldTs);

        $cleaned = $this->storage->gc(3600);

        $this->assertSame(1, $cleaned);
        $this->assertFileDoesNotExist($sessionFile);
        $this->assertFileExists($tokenFile);
        $this->assertFileExists($datFile);
        @\unlink($tokenFile);
        @\unlink($datFile);
    }

    public function testGetConfig(): void
    {
        $config = $this->storage->getConfig();
        
        $this->assertIsArray($config);
        $this->assertEquals($this->testPath, $config['path']);
    }

    public function testComplexData(): void
    {
        $sessionId = 'test_complex_' . \bin2hex(\random_bytes(8));
        $data = [
            'string' => 'value',
            'integer' => 123,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => ['a' => ['b' => 'c']],
        ];
        
        $this->storage->write($sessionId, $data, 3600);
        
        $readData = $this->storage->read($sessionId);
        
        $this->assertEquals($data, $readData);
        
        $this->storage->destroy($sessionId);
    }
}
