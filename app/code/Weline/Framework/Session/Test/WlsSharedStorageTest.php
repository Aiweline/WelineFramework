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
        $facade->expects($this->once())
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
}
