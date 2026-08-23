<?php

declare(strict_types=1);

namespace Weline\Storage\Adapter;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageInterface;

/** @deprecated Compatibility for legacy callers. New business code must use StorageDiskInterface. */
final readonly class StorageInterfaceBridge implements StorageInterface
{
    private const MAX_LEGACY_BUFFER_BYTES = 16 * 1024 * 1024;
    private const MAX_LEGACY_BATCH_ITEMS = 1000;

    public function __construct(private StorageDiskInterface $disk)
    {
    }

    public function getDriver(): string { return $this->disk->snapshot()->code()->providerCode(); }
    public function put(string $path, $contents, array $options = []): bool
    {
        if (!is_resource($contents) && strlen((string)$contents) > self::MAX_LEGACY_BUFFER_BYTES) {
            return false;
        }
        $stream = is_resource($contents) ? $contents : fopen('php://temp', 'w+b');
        if (!is_resource($stream)) { return false; }
        if (!is_resource($contents)) {
            if (!$this->writeFully($stream, (string)$contents) || !rewind($stream)) {
                fclose($stream);
                return false;
            }
        }
        $options['overwrite'] = true;
        $options['max_bytes'] = self::MAX_LEGACY_BUFFER_BYTES;
        try { $this->disk->writeStream($path, $stream, $options); return true; }
        catch (\Throwable) { return false; }
        finally { if (!is_resource($contents) && is_resource($stream)) { fclose($stream); } }
    }
    public function putStream(string $path, $resource, array $options = []): bool { return $this->put($path, $resource, $options); }
    public function get(string $path): ?string
    {
        try {
            if ($this->disk->stat($path)->bytes > self::MAX_LEGACY_BUFFER_BYTES) {
                return null;
            }
            $handle = $this->disk->openRead($path);
            $bytes = '';
            $emptyReads = 0;
            try {
                while (!$handle->eof()) {
                    $chunk = $handle->read();
                    if ($chunk === '') {
                        if (++$emptyReads >= 3) {
                            return null;
                        }
                        SchedulerSystem::yield();
                        continue;
                    }
                    $emptyReads = 0;
                    $bytes .= $chunk;
                    if (strlen($bytes) > self::MAX_LEGACY_BUFFER_BYTES) {
                        return null;
                    }
                    SchedulerSystem::yield();
                }
            }
            finally { $handle->close(); }
            return $bytes;
        } catch (\Throwable) { return null; }
    }
    public function getStream(string $path)
    {
        $handle = null;
        $stream = null;
        $returned = false;
        try {
            if ($this->disk->stat($path)->bytes > self::MAX_LEGACY_BUFFER_BYTES) {
                return null;
            }
            $handle = $this->disk->openRead($path);
            $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (!is_resource($stream)) {
                return null;
            }
            $copiedBytes = 0;
            $emptyReads = 0;
            while (!$handle->eof()) {
                $chunk = $handle->read();
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        return null;
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                $copiedBytes += strlen($chunk);
                if ($copiedBytes > self::MAX_LEGACY_BUFFER_BYTES || !$this->writeFully($stream, $chunk)) {
                    return null;
                }
                SchedulerSystem::yield();
            }
            if (!rewind($stream)) {
                return null;
            }
            $handle->close();
            $handle = null;
            $returned = true;
            return $stream;
        } catch (\Throwable) {
            return null;
        } finally {
            try {
                if ($handle !== null) {
                    $handle->close();
                }
            } finally {
                if (!$returned && is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
    public function delete(string $path): bool { return $this->disk->delete($path); }
    public function deleteMultiple(array $paths): bool
    {
        if (count($paths) > self::MAX_LEGACY_BATCH_ITEMS) { return false; }
        $ok = true;
        foreach ($paths as $path) { $ok = $this->delete((string)$path) && $ok; }
        return $ok;
    }
    public function exists(string $path): bool { return $this->disk->exists($path); }
    public function url(string $path): ?string { try { return $this->disk->resolveUrl($path)->url; } catch (\Throwable) { return null; } }
    public function temporaryUrl(string $path, int $expiration = 3600): ?string
    { try { return $this->disk->resolveUrl($path, new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, $expiration))->url; } catch (\Throwable) { return null; } }
    public function size(string $path): ?int { try { return $this->disk->stat($path)->bytes; } catch (\Throwable) { return null; } }
    public function lastModified(string $path): ?int { try { return $this->disk->stat($path)->lastModified; } catch (\Throwable) { return null; } }
    public function mimeType(string $path): ?string { try { return $this->disk->stat($path)->mimeType; } catch (\Throwable) { return null; } }
    public function copy(string $from, string $to): bool { try { $this->disk->copy($from, $to); return true; } catch (\Throwable) { return false; } }
    public function move(string $from, string $to): bool { try { $this->disk->move($from, $to); return true; } catch (\Throwable) { return false; } }
    public function list(string $directory = '', bool $recursive = false): array
    {
        return array_map(static fn ($stat): array => [
            'path' => $stat->object->objectKey,
            'type' => $stat->metadata['type'] ?? 'file',
            'size' => $stat->bytes,
            'last_modified' => $stat->lastModified,
            'mime_type' => $stat->mimeType,
        ], $this->disk->list($directory, $recursive));
    }
    public function makeDirectory(string $path): bool { return $this->disk->makeDirectory($path); }
    public function deleteDirectory(string $path): bool { return $this->disk->deleteDirectory($path); }
    public function testConnection(): bool { return true; }
    public function getInfo(): array { return ['driver' => $this->getDriver(), 'disk_code' => $this->disk->diskCode(), 'config_revision' => $this->disk->snapshot()->configRevision]; }

    /** @param resource $stream */
    private function writeFully(mixed $stream, string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written < 1) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }
}
