<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Server\Service\MemoryStateFacade;

final class DevToolPayloadStoreTest extends TestCase
{
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanup as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        $this->filesToCleanup = [];
    }

    public function testSetFallsBackToFileWhenMemoryWriteReturnsFalse(): void
    {
        $key = 'trace:test-' . \bin2hex(\random_bytes(4));
        $value = ['ok' => 1];

        $memory = $this->createMock(MemoryStateFacade::class);
        $memory->expects(self::once())
            ->method('set')
            ->with('dev_tool_payload', 'trace:' . $key, $value, 60)
            ->willReturn(false);

        $store = new DevToolPayloadStore();
        $this->setPrivateProperty($store, 'memoryResolved', true);
        $this->setPrivateProperty($store, 'memory', $memory);

        self::assertTrue($store->set('trace', $key, $value, 60));

        $fileStore = new DevToolPayloadStore(['force_file' => true]);
        $filePath = $this->invokePrivateMethod($fileStore, 'filePath', ['trace', $key]);
        if (\is_string($filePath)) {
            $this->filesToCleanup[] = $filePath;
        }

        self::assertSame($value, $fileStore->get('trace', $key));
    }

    public function testTraceWritesFileMirrorEvenWhenMemoryWriteSucceeds(): void
    {
        $key = 'trace:test-' . \bin2hex(\random_bytes(4));
        $value = ['ok' => 2];

        $memory = $this->createMock(MemoryStateFacade::class);
        $memory->expects(self::once())
            ->method('set')
            ->with('dev_tool_payload', 'trace:' . $key, $value, 60)
            ->willReturn(true);

        $store = new DevToolPayloadStore();
        $this->setPrivateProperty($store, 'memoryResolved', true);
        $this->setPrivateProperty($store, 'memory', $memory);

        self::assertTrue($store->set('trace', $key, $value, 60));

        $fileStore = new DevToolPayloadStore(['force_file' => true]);
        $filePath = $this->invokePrivateMethod($fileStore, 'filePath', ['trace', $key]);
        if (\is_string($filePath)) {
            $this->filesToCleanup[] = $filePath;
        }

        self::assertSame($value, $fileStore->get('trace', $key));
    }

    public function testGetLatestReturnsNewestFilePayloadWithinWindow(): void
    {
        $store = new DevToolPayloadStore(['force_file' => true]);
        $oldKey = 'trace:test-old-' . \bin2hex(\random_bytes(4));
        $newKey = 'trace:test-new-' . \bin2hex(\random_bytes(4));
        $oldValue = ['ok' => 'old'];
        $newValue = ['ok' => 'new'];

        self::assertTrue($store->set('trace', $oldKey, $oldValue, 60));
        \usleep(1000);
        self::assertTrue($store->set('trace', $newKey, $newValue, 60));

        $newFilePath = '';
        foreach ([$oldKey, $newKey] as $key) {
            $filePath = $this->invokePrivateMethod($store, 'filePath', ['trace', $key]);
            if (\is_string($filePath)) {
                $this->filesToCleanup[] = $filePath;
                if ($key === $newKey) {
                    $newFilePath = $filePath;
                }
            }
        }
        if ($newFilePath !== '') {
            @\touch($newFilePath, \time() + 1);
        }

        self::assertSame($newValue, $store->getLatest('trace', 60));
    }

    private function setPrivateProperty(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function invokePrivateMethod(object $object, string $name, array $args = []): mixed
    {
        $method = new \ReflectionMethod($object, $name);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
