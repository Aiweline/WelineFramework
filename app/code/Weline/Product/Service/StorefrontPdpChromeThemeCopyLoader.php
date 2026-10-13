<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * Loads optional PDP chrome phrase overlays from the bound design theme.
 *
 * File: {@code app/design/{Vendor}/{theme}/data/pdp-chrome.php}
 * Keys are Chinese Phrase sources for {@see __()} — never locale strings in CSS.
 *
 * @phpstan-type PdpChromePack array{
 *     add_to_cart_phrase?:string
 * }
 */
final class StorefrontPdpChromeThemeCopyLoader
{
    private const RELATIVE_FILE = 'data/pdp-chrome.php';

    /** @var (callable():(?string))|null */
    private $themePathResolver;

    /**
     * @param (callable():(?string))|null $themePathResolver Injected for tests.
     */
    public function __construct(?callable $themePathResolver = null)
    {
        $this->themePathResolver = $themePathResolver;
    }

    /**
     * Chinese Phrase source for the primary add-to-cart CTA, or empty when unset.
     */
    public function addToCartPhrase(): string
    {
        $pack = $this->load();
        $phrase = trim((string)($pack['add_to_cart_phrase'] ?? ''));

        return $phrase;
    }

    /**
     * @return PdpChromePack
     */
    public function load(): array
    {
        $themePath = $this->resolveThemePath();
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

    private function resolveThemePath(): string
    {
        if ($this->themePathResolver !== null) {
            try {
                $resolved = ($this->themePathResolver)();
            } catch (\Throwable) {
                $resolved = null;
            }
            if (!is_string($resolved) || trim($resolved) === '') {
                return '';
            }

            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolved), DIRECTORY_SEPARATOR);
        }

        $bound = $this->resolveBoundFrontendThemePath();
        if ($bound !== '' && is_file($bound . DIRECTORY_SEPARATOR . self::RELATIVE_FILE)) {
            return $bound;
        }

        // Fallback: design root / Weline / {theme-or-website code} (tests / unbound).
        $code = $this->resolveThemeOrWebsiteCode();
        if ($code === '') {
            return '';
        }
        $designRoot = $this->designRoot();
        if ($designRoot === '') {
            return '';
        }
        $candidate = $designRoot
            . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . $code;
        if (is_file($candidate . DIRECTORY_SEPARATOR . self::RELATIVE_FILE)) {
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
        }

        return '';
    }

    private function resolveThemeOrWebsiteCode(): string
    {
        try {
            if (class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
                $websiteCode = trim((string)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode());
                if ($websiteCode !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/D', strtolower($websiteCode))) {
                    return strtolower($websiteCode);
                }
            }
        } catch (\Throwable) {
        }
        try {
            $bound = $this->resolveBoundFrontendThemePath();
            if ($bound !== '') {
                $base = strtolower(basename($bound));
                if ($base !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $base)) {
                    return $base;
                }
            }
        } catch (\Throwable) {
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
