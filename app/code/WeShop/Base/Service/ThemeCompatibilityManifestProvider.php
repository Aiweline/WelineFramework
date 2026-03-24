<?php

declare(strict_types=1);

namespace WeShop\Base\Service;

class ThemeCompatibilityManifestProvider
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'theme-compatibility.php';
        if (!is_file($manifestFile)) {
            $this->manifest = [];
            return $this->manifest;
        }

        $manifest = require $manifestFile;
        $this->manifest = is_array($manifest) ? $manifest : [];

        return $this->manifest;
    }
}
