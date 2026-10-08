<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Durable solidify job intents under generated/theme-layout-entities/{theme}/.solidify-jobs/.
 * Cron (and optional WLS post-response) drain these; FPM cannot rely on in-memory queues.
 */
final class ThemeLayoutEntitySolidifyJobStore
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    public function has(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        return is_file($this->pathFor($key));
    }

    /**
     * @return array{enqueued:bool,coalesced:bool}
     */
    public function put(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFingerprint): array
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            return ['enqueued' => false, 'coalesced' => true];
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('theme_layout_solidify_job_dir_failed');
        }
        $payload = json_encode([
            'serial' => $key->toString(),
            'theme_id' => $key->themeId,
            'area' => $key->area,
            'scope' => $key->canonicalScope,
            'store_mode' => $key->storeMode,
            'layout_type' => $key->layoutType,
            'layout_option' => $key->layoutOption,
            'theme_version_id' => $key->themeVersionId,
            'content_revision' => $key->contentRevision,
            'expected_fp' => trim($expectedFingerprint),
            'queued_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('theme_layout_solidify_job_write_failed');
        }
        if (!@rename($tmp, $path)) {
            // Lost the race — another writer created the job.
            @unlink($tmp);
            if (is_file($path)) {
                return ['enqueued' => false, 'coalesced' => true];
            }
            throw new \RuntimeException('theme_layout_solidify_job_rename_failed');
        }

        return ['enqueued' => true, 'coalesced' => false];
    }

    public function delete(ThemeLayoutEntitySolidifySerialKey $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string}>
     */
    public function listPending(int $limit = 32): array
    {
        $root = rtrim($this->paths->root(), '/\\');
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $limit = max(1, min(200, $limit));
        $themeDirs = @scandir($root) ?: [];
        foreach ($themeDirs as $themeName) {
            if ($themeName === '.' || $themeName === '..' || !ctype_digit($themeName)) {
                continue;
            }
            $jobDir = $root . DIRECTORY_SEPARATOR . $themeName . DIRECTORY_SEPARATOR . '.solidify-jobs';
            if (!is_dir($jobDir)) {
                continue;
            }
            $files = @scandir($jobDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                    continue;
                }
                $path = $jobDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($path)) {
                    continue;
                }
                $parsed = $this->readJobFile($path);
                if ($parsed === null) {
                    continue;
                }
                $out[] = $parsed;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @return null|array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string}
     */
    private function readJobFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
            (int)($data['theme_id'] ?? 0),
            (string)($data['area'] ?? 'frontend'),
            (string)($data['scope'] ?? 'default.default.default'),
            (string)($data['store_mode'] ?? 'normal'),
            (string)($data['layout_type'] ?? 'homepage'),
            (string)($data['layout_option'] ?? 'default'),
            (int)($data['theme_version_id'] ?? 0),
            (int)($data['content_revision'] ?? 0),
        );
        if ($key->themeId < 1) {
            return null;
        }

        return [
            'key' => $key,
            'expected_fp' => trim((string)($data['expected_fp'] ?? '')),
            'path' => $path,
        ];
    }

    private function pathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return rtrim($this->paths->root(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-jobs'
            . DIRECTORY_SEPARATOR . $key->hash() . '.json';
    }
}
