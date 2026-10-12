<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * Loads website/theme catalog surface overlays from design theme packages.
 *
 * Production: prefer the bound frontend theme path (ThemeContext).
 * Fallback (tests / unbound): {@code app/design/Weline/{websiteCode}/data/catalog-surfaces.php}
 * when that file exists — never invent brand copy inside Product.
 *
 * @phpstan-type SurfaceLocaleCopy array{
 *     title:string,
 *     heading:string,
 *     lede:string,
 *     seo_title:string,
 *     seo_description:string,
 *     seo_keywords:string,
 *     share_image_alt:string
 * }
 * @phpstan-type ThemeCatalogSurfaces array{
 *     share_image?:string,
 *     surfaces?:array<string, array{zh?:SurfaceLocaleCopy, en?:SurfaceLocaleCopy}>,
 *     presentation?:array<string, array<string, mixed>>
 * }
 */
final class StorefrontCatalogSurfaceThemeCopyLoader
{
    private const RELATIVE_FILE = 'data/catalog-surfaces.php';

    /** @var (callable(string):(?string))|null */
    private $themePathResolver;

    /**
     * @param (callable(string):(?string))|null $themePathResolver Injected for tests; production resolves via ThemeContext.
     */
    public function __construct(?callable $themePathResolver = null)
    {
        $this->themePathResolver = $themePathResolver;
    }

    /**
     * @return ThemeCatalogSurfaces
     */
    public function load(string $websiteCode): array
    {
        $themePath = $this->resolveThemePath($websiteCode);
        if ($themePath === '') {
            return [];
        }

        $file = $themePath . DIRECTORY_SEPARATOR . self::RELATIVE_FILE;
        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $payload */
        $payload = require $file;
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    public function hasSurfaces(string $websiteCode): bool
    {
        $pack = $this->load($websiteCode);
        $surfaces = $pack['surfaces'] ?? null;

        return is_array($surfaces) && $surfaces !== [];
    }

    private function resolveThemePath(string $websiteCode): string
    {
        // Injected resolver owns the whole lookup (tests): empty string = no pack.
        if ($this->themePathResolver !== null) {
            try {
                $resolved = ($this->themePathResolver)($websiteCode);
            } catch (\Throwable) {
                $resolved = null;
            }
            if (!is_string($resolved) || trim($resolved) === '') {
                return '';
            }

            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolved), DIRECTORY_SEPARATOR);
        }

        $bound = $this->resolveBoundFrontendThemePath();
        if ($bound !== '') {
            $file = $bound . DIRECTORY_SEPARATOR . self::RELATIVE_FILE;
            if (is_file($file)) {
                return $bound;
            }
        }

        $code = strtolower(trim($websiteCode));
        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $code)) {
            return '';
        }

        $designRoot = $this->designRoot();
        if ($designRoot === '') {
            return '';
        }
        $candidate = $designRoot
            . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . $code;
        $file = $candidate . DIRECTORY_SEPARATOR . self::RELATIVE_FILE;
        if (is_file($file)) {
            return $candidate;
        }

        return '';
    }

    private function designRoot(): string
    {
        if (\defined('BP') && \is_string(BP) && BP !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, BP), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'app'
                . DIRECTORY_SEPARATOR . 'design';
        }

        try {
            $fromEnv = (string)Env::path_THEME_DESIGN_DIR;
            if ($fromEnv !== '') {
                return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fromEnv), DIRECTORY_SEPARATOR);
            }
        } catch (\Throwable) {
            // BP / Env unavailable in bare unit harness.
        }

        return '';
    }

    private function resolveBoundFrontendThemePath(): string
    {
        try {
            if (!class_exists(\Weline\Theme\Service\ThemeContextService::class)) {
                return '';
            }
            /** @var object $context */
            $context = ObjectManager::getInstance(\Weline\Theme\Service\ThemeContextService::class);
            if (!method_exists($context, 'resolveTheme')) {
                return '';
            }
            $theme = $context->resolveTheme('frontend', null, false);
            if (!is_object($theme) || !method_exists($theme, 'getPath')) {
                return '';
            }
            $path = trim((string)$theme->getPath());
            if ($path === '') {
                return '';
            }

            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        } catch (\Throwable) {
            return '';
        }
    }
}
