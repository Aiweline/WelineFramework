<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Storage\WlsSharedStorage;
use Weline\Server\Service\SessionStateFacade;

final class WlsSharedStorageTest extends TestCase
{
    private string $testPath = 'var/test_wls_shared_storage/';

    protected function tearDown(): void
    {
        $path = BP . \str_replace('/', DS, $this->testPath);
        if (!\is_dir($path)) {
            return;
        }

        foreach (\glob($path . '*') ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    public function testFallsBackToFileStorageWhenSharedFacadeFailsFast(): void
    {
        $storage = new WlsSharedStorage(
            [
                'path' => $this->testPath,
                'lifetime' => 3600,
            ],
            static function (): SessionStateFacade {
                throw new RuntimeException('shared session unavailable');
            }
        );

        self::assertTrue($storage->write('fallback-session', ['foo' => 'bar'], 3600));
        self::assertSame(['foo' => 'bar'], $storage->read('fallback-session'));
        self::assertFalse($storage->ping());
        self::assertTrue($storage->destroy('fallback-session'));

        $stats = $storage->getStats();
        self::assertSame('file_fallback', $stats['mode'] ?? null);
        self::assertSame('shared session unavailable', $stats['fallback_reason'] ?? null);
    }

    public function testUsesSharedFacadeWhenAvailable(): void
    {
        $facade = $this->getMockBuilder(SessionStateFacade::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'write', 'destroy', 'exists', 'touch', 'gc', 'getStats', 'ping', 'list'])
            ->getMock();

        $facade->expects($this->once())
            ->method('write')
            ->with('shared-session', ['foo' => 'bar'], 3600)
            ->willReturn(true);
        $facade->expects($this->once())
            ->method('read')
            ->with('shared-session')
            ->willReturn(['foo' => 'bar']);
        $facade->expects($this->atLeastOnce())
            ->method('ping')
            ->willReturn(true);
        $facade->expects($this->once())
            ->method('getStats')
            ->willReturn(['healthy' => true]);

        $storage = new WlsSharedStorage(
            ['path' => $this->testPath, 'lifetime' => 3600],
            static fn(): SessionStateFacade => $facade,
            new FileStorage(['path' => $this->testPath, 'lifetime' => 3600])
        );

        self::assertTrue($storage->write('shared-session', ['foo' => 'bar'], 3600));
        self::assertSame(['foo' => 'bar'], $storage->read('shared-session'));
        self::assertTrue($storage->ping());
        self::assertSame('strong_consistency', $storage->getStats()['mode'] ?? null);
    }

    /**
     * 共享存储有连接但某 sid 仅在文件 fallback 中有数据时，read 应回灌到共享存储，避免「登录写文件、下次读内存为空」。
     */
    public function testReadRepairsFromFileWhenSharedEmpty(): void
    {
        $file = new FileStorage(['path' => $this->testPath, 'lifetime' => 3600]);
        $file->write('split-brain-session', ['WF_BACKEND_USER_ID' => 1], 3600);

        $facade = $this->getMockBuilder(SessionStateFacade::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'write', 'destroy', 'exists', 'touch', 'gc', 'getStats', 'ping', 'list'])
            ->getMock();

        $facade->method('read')
            ->with('split-brain-session')
            ->willReturn([]);
        $facade->expects($this->once())
            ->method('write')
            ->with('split-brain-session', ['WF_BACKEND_USER_ID' => 1], 3600)
            ->willReturn(true);
        $facade->method('ping')->willReturn(true);
        $facade->method('getStats')->willReturn([]);

        $storage = new WlsSharedStorage(
            ['path' => $this->testPath, 'lifetime' => 3600],
            static fn(): SessionStateFacade => $facade,
            $file
        );

        $data = $storage->read('split-brain-session');
        self::assertSame(['WF_BACKEND_USER_ID' => 1], $data);
        self::assertSame(['WF_BACKEND_USER_ID' => 1], $file->read('split-brain-session'));
    }

    public function testWriteFallsBackAndEntersCooldownWhenFacadeLosesHealth(): void
    {
        $facade = $this->getMockBuilder(SessionStateFacade::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'write', 'destroy', 'exists', 'touch', 'gc', 'getStats', 'ping', 'list', 'disconnect'])
            ->getMock();

        $facade->expects($this->once())
            ->method('write')
            ->with('fallback-after-write', ['foo' => 'bar'], 3600)
            ->willReturn(false);
        $facade->expects($this->atLeastOnce())
            ->method('disconnect');

        $storage = new WlsSharedStorage(
            ['path' => $this->testPath, 'lifetime' => 3600, 'fallback_retry_interval_sec' => 5],
            static fn(): SessionStateFacade => $facade,
            new FileStorage(['path' => $this->testPath, 'lifetime' => 3600])
        );

        self::assertTrue($storage->write('fallback-after-write', ['foo' => 'bar'], 3600));
        self::assertSame(['foo' => 'bar'], $storage->read('fallback-after-write'));

        $stats = $storage->getStats();
        self::assertSame('file_fallback', $stats['mode'] ?? null);
        self::assertSame('Shared session facade is not healthy after write', $stats['fallback_reason'] ?? null);
        self::assertFalse($storage->ping());
    }

    public function testReadRepairFallsBackWhenFacadeCannotBeRehydrated(): void
    {
        $file = new FileStorage(['path' => $this->testPath, 'lifetime' => 3600]);
        $file->write('repair-fallback-session', ['WF_BACKEND_USER_ID' => 2], 3600);

        $facade = $this->getMockBuilder(SessionStateFacade::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'write', 'destroy', 'exists', 'touch', 'gc', 'getStats', 'ping', 'list', 'disconnect'])
            ->getMock();

        $facade->expects($this->once())
            ->method('read')
            ->with('repair-fallback-session')
            ->willReturn([]);
        $facade->expects($this->once())
            ->method('write')
            ->with('repair-fallback-session', ['WF_BACKEND_USER_ID' => 2], 3600)
            ->willReturn(false);
        $facade->expects($this->atLeastOnce())
            ->method('disconnect');

        $storage = new WlsSharedStorage(
            ['path' => $this->testPath, 'lifetime' => 3600, 'fallback_retry_interval_sec' => 5],
            static fn(): SessionStateFacade => $facade,
            $file
        );

        $data = $storage->read('repair-fallback-session');
        self::assertSame(['WF_BACKEND_USER_ID' => 2], $data);

        $stats = $storage->getStats();
        self::assertSame('file_fallback', $stats['mode'] ?? null);
        self::assertSame('Shared session facade lost health during read repair', $stats['fallback_reason'] ?? null);
    }

    /**
     * 共享侧 GC 与 var/session 镜像 GC 需同时执行，返回值为两者清理数量之和。
     */
    public function testGcRunsSharedAndFallbackFiles(): void
    {
        $file = new FileStorage(['path' => $this->testPath, 'lifetime' => 3600]);
        $hex = 'b2c3d4e5f6789012345678abcdef0123';
        $file->write($hex, ['k' => 1], 3600);
        $path = BP . \str_replace('/', DS, $this->testPath) . $hex;
        \touch($path, \time() - 7200);

        $facade = $this->getMockBuilder(SessionStateFacade::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'write', 'destroy', 'exists', 'touch', 'gc', 'getStats', 'ping', 'list'])
            ->getMock();
        $facade->method('ping')->willReturn(true);
        $facade->expects($this->once())->method('gc')->with(3600)->willReturn(2);

        $storage = new WlsSharedStorage(
            ['path' => $this->testPath, 'lifetime' => 3600],
            static fn(): SessionStateFacade => $facade,
            $file
        );

        self::assertSame(3, $storage->gc(3600));
        self::assertFileDoesNotExist($path);
    }
}
