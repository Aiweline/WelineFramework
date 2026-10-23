<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

final class StaticPublishFingerprint
{
    private string $storeDir;

    public function __construct(string $storeDir)
    {
        $this->storeDir = rtrim($storeDir, '/\\');
    }

    public function compute(array $inputs): string
    {
        $json = json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return hash('sha256', serialize($inputs));
        }
        return hash('sha256', $json);
    }

    public function path(string $kind): string
    {
        if ($kind === '') {
            throw new \InvalidArgumentException('Fingerprint kind cannot be empty.');
        }
        return $this->storeDir . DIRECTORY_SEPARATOR . $kind . '.json';
    }

    public function matches(string $kind, string $fingerprint): bool
    {
        if ($kind === '') {
            throw new \InvalidArgumentException('Fingerprint kind cannot be empty.');
        }
        $file = $this->path($kind);
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !isset($data['fingerprint'])) {
            return false;
        }
        return (string)$data['fingerprint'] === $fingerprint;
    }

    public function remember(string $kind, string $fingerprint): void
    {
        if ($kind === '') {
            throw new \InvalidArgumentException('Fingerprint kind cannot be empty.');
        }
        $file = $this->path($kind);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return;
        }
        file_put_contents($file, json_encode([
            'fingerprint' => $fingerprint,
            'saved_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
