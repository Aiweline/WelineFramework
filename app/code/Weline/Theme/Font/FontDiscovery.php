<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;

use Weline\Framework\App\Env;
use Weline\Framework\View\Data\DataInterface;

/**
 * Discover font files from the framework convention directory:
 * `{Module}/view/fonts/` recursive, extensions ttf/otf/woff/woff2.
 *
 * Modules only need to drop files there; upgrade warmup picks them up automatically.
 */
class FontDiscovery
{
    public const EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2'];

    /**
     * @param array<string,array<string,mixed>>|null $modules Env module list; null = load from Env
     * @return list<array{path:string,module:string,relative:string,source:string}>
     */
    public function discover(?array $modules = null): array
    {
        $modules ??= $this->loadModules();
        $out = [];
        $seen = [];

        foreach ($modules as $moduleName => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if (isset($meta['status']) && !(bool)$meta['status']) {
                continue;
            }
            $base = (string)($meta['base_path'] ?? '');
            if ($base === '') {
                continue;
            }
            $fontsDir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR
                . DataInterface::dir . DIRECTORY_SEPARATOR
                . DataInterface::view_FONTS_DIR;
            if (!is_dir($fontsDir)) {
                continue;
            }

            foreach ($this->scanFontFiles($fontsDir) as $absolute) {
                $real = realpath($absolute) ?: $absolute;
                if (isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $fontsDirReal = realpath($fontsDir) ?: $fontsDir;
                $relative = $real;
                if (str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $fontsDirReal))) {
                    $relative = ltrim(substr(str_replace('\\', '/', $real), strlen(str_replace('\\', '/', $fontsDirReal))), '/');
                } else {
                    $relative = basename($real);
                }
                $out[] = [
                    'path' => $real,
                    'module' => (string)$moduleName,
                    'relative' => $relative,
                    'source' => $moduleName . '::' . $relative,
                ];
            }
        }

        return $out;
    }

    /**
     * Resolve a font under `{Module}/view/fonts/`.
     *
     * - `Vendor_Module::path/font.ttf` — explicit module
     * - `path/font.ttf` — defaults to Weline_Theme (same default as theme:css / theme:js)
     * - absolute readable filesystem path — used as-is
     */
    public function resolveSource(string $source, ?array $modules = null): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        if (!str_contains($source, '::')) {
            if (is_file($source) && is_readable($source)) {
                $real = realpath($source);

                return $real !== false ? $real : $source;
            }

            // Bare relative path → default Theme module (like theme:css / theme:js).
            $source = 'Weline_Theme::' . ltrim(str_replace('\\', '/', $source), '/');
        }

        [$moduleName, $relative] = array_pad(explode('::', $source, 2), 2, '');
        $moduleName = trim($moduleName);
        $relative = ltrim(str_replace(['\\', '..'], ['/', ''], trim($relative)), '/');
        if ($moduleName === '' || $relative === '') {
            return null;
        }

        $modules ??= $this->loadModules();
        $meta = $modules[$moduleName] ?? null;
        if (!is_array($meta)) {
            return null;
        }
        $base = (string)($meta['base_path'] ?? '');
        if ($base === '') {
            return null;
        }

        $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR
            . DataInterface::dir . DIRECTORY_SEPARATOR
            . DataInterface::view_FONTS_DIR . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    /**
     * @return list<string>
     */
    private function scanFontFiles(string $fontsDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fontsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadModules(): array
    {
        try {
            $list = Env::getInstance()->getModuleList();

            return is_array($list) ? $list : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
