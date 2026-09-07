<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class RunArtifactStore
{
    /** @var list<string> */
    private const ALLOWED_NAMES = [
        'verified-sources.json',
        'source-evidence.json',
        'crawl-snapshot.json',
        'collect-report.json',
        'import-preview.json',
        'import-report.json',
        'verification.json',
    ];

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 6) . '/var/hanfu-1688', '/');
    }

    public function runDirectory(string $runId): string
    {
        $runId = strtolower(trim($runId));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/D', $runId) !== 1
            || str_contains($runId, '..')
        ) {
            throw new \InvalidArgumentException('hanfu_1688_run_id_invalid');
        }
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('hanfu_1688_artifact_root_create_failed');
        }
        chmod($this->root, 0700);
        $directory = $this->root . '/' . $runId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('hanfu_1688_artifact_directory_create_failed');
        }
        chmod($directory, 0700);
        return $directory;
    }

    /** @return array<string,mixed> */
    public function readJson(string $runId, string $name): array
    {
        $path = $this->path($runId, $name);
        if (!is_file($path)) {
            throw new \RuntimeException('hanfu_1688_artifact_missing');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('hanfu_1688_artifact_read_failed');
        }
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \RuntimeException('hanfu_1688_artifact_json_invalid');
        }
        return $document;
    }

    /** @param array<string,mixed> $document */
    public function writeJson(string $runId, string $name, array $document): string
    {
        $path = $this->path($runId, $name);
        $bytes = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $temporary = tempnam(dirname($path), '.' . $name . '.tmp-');
        if (!is_string($temporary)) {
            throw new \RuntimeException('hanfu_1688_artifact_temp_failed');
        }
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('hanfu_1688_artifact_write_failed');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('hanfu_1688_artifact_rename_failed');
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $path;
    }

    private function path(string $runId, string $name): string
    {
        if (!in_array($name, self::ALLOWED_NAMES, true)) {
            throw new \InvalidArgumentException('hanfu_1688_artifact_name_invalid');
        }
        return $this->runDirectory($runId) . '/' . $name;
    }
}
