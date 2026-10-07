<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/** Persists expected injection fingerprint after a successful async solidify. */
final class ThemeLayoutEntitySolidifyStampStore
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    public function read(ThemeLayoutEntitySolidifySerialKey $key): ?string
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $fp = trim((string)($data['injection_fingerprint'] ?? ''));

        return $fp !== '' ? $fp : null;
    }

    public function write(ThemeLayoutEntitySolidifySerialKey $key, string $injectionFingerprint): void
    {
        $fp = trim($injectionFingerprint);
        if ($fp === '') {
            return;
        }
        $path = $this->pathFor($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('theme_layout_solidify_stamp_dir_failed');
        }
        $payload = json_encode([
            'injection_fingerprint' => $fp,
            'serial_key' => $key->toString(),
            'written_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload) === false) {
            throw new \RuntimeException('theme_layout_solidify_stamp_write_failed');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('theme_layout_solidify_stamp_rename_failed');
        }
    }

    private function pathFor(ThemeLayoutEntitySolidifySerialKey $key): string
    {
        return rtrim($this->paths->root(), '/\\')
            . DIRECTORY_SEPARATOR . (string)$key->themeId
            . DIRECTORY_SEPARATOR . '.solidify-stamps'
            . DIRECTORY_SEPARATOR . $key->hash() . '.json';
    }
}
