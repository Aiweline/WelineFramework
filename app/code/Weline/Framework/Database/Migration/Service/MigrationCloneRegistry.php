<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

use Weline\Framework\Database\Migration\MigrationCloneHandle;

/**
 * 隔离 clone 登记簿：指纹 allowlist + 创建/销毁命令审计（不含密码）。
 */
final class MigrationCloneRegistry
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $resolved = $directory ?? self::defaultDirectory();
        if ($resolved === '') {
            throw new \InvalidArgumentException('migration_clone_registry_dir_empty');
        }
        if (!\is_dir($resolved) && !@\mkdir($resolved, 0775, true) && !\is_dir($resolved)) {
            throw new \RuntimeException('migration_clone_registry_mkdir_failed:' . $resolved);
        }
        $this->directory = $resolved;
    }

    public static function defaultDirectory(): string
    {
        $bp = \defined('BP') ? BP : \dirname(__DIR__, 7);

        return $bp . '/var/mig/clones';
    }

    public function register(MigrationCloneHandle $handle): void
    {
        if ($handle->database === '' || $handle->fingerprint === '') {
            throw new \InvalidArgumentException('migration_clone_handle_incomplete');
        }
        $path = $this->pathFor($handle->database);
        $json = \json_encode($handle->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        if (@\file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('migration_clone_registry_write_failed:' . $path);
        }
    }

    public function forget(string $database): void
    {
        $path = $this->pathFor($database);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    public function get(string $database): ?MigrationCloneHandle
    {
        $path = $this->pathFor($database);
        if (!\is_file($path)) {
            return null;
        }
        $raw = \file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }

        return MigrationCloneHandle::fromArray($data);
    }

    /**
     * @return list<MigrationCloneHandle>
     */
    public function list(): array
    {
        $out = [];
        foreach (\glob($this->directory . '/*.json') ?: [] as $file) {
            $raw = \file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            try {
                $data = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (\is_array($data)) {
                $out[] = MigrationCloneHandle::fromArray($data);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function allowlistFingerprints(): array
    {
        $fps = [];
        foreach ($this->list() as $handle) {
            if ($handle->fingerprint !== '') {
                $fps[] = $handle->fingerprint;
            }
        }

        return \array_values(\array_unique($fps));
    }

    private function pathFor(string $database): string
    {
        $safe = \preg_replace('/[^a-zA-Z0-9_.-]/', '_', $database) ?? 'unknown';

        return $this->directory . '/' . $safe . '.json';
    }
}
