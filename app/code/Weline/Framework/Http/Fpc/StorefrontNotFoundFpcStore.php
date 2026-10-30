<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;
use Weline\Framework\Http\StorefrontLocale;
use Weline\Framework\View\Helper\HtmlCacheAdmission;

/**
 * Special per-language FPC store for storefront 404 pages.
 *
 * The normal {@see FpcHtmlDiskStore} deliberately refuses error documents
 * (`data-layout="not_found"`), so the 404 page lives in its own disk tree,
 * keyed by websiteCode × locale instead of request URL. Any unmatched URL can
 * therefore serve the same stable page while the visitor's requested URL stays
 * untouched (404 status, no redirect).
 *
 * Layout: generated/error-pages/not-found/{websiteCode}/{lang}.html (+ .ok marker)
 *
 * Populated at runtime by {@see \Weline\Framework\Http\ErrorPageRenderer} from
 * the theme-rendered `not_found` layout (see Theme\Service\StorefrontNotFoundRenderer),
 * never by setup:upgrade.
 */
final class StorefrontNotFoundFpcStore
{
    public const DIR_SEGMENT = 'error-pages/not-found';

    public static function root(): string
    {
        $base = \rtrim((string)Env::GENERATED_DIR, '\\/');

        return $base . \DIRECTORY_SEPARATOR . 'error-pages' . \DIRECTORY_SEPARATOR . 'not-found';
    }

    public static function filePath(string $websiteCode, string $lang, string $suffix = '.html'): string
    {
        $code = self::sanitizeCode($websiteCode);
        $lang = StorefrontLocale::normalizeLangCode($lang) ?: StorefrontLocale::DEFAULT_LANG;

        return self::root() . \DIRECTORY_SEPARATOR . $code . \DIRECTORY_SEPARATOR . $lang . $suffix;
    }

    public static function sanitizeCode(string $code): string
    {
        $code = \trim($code);
        if ($code === '' || \preg_match('/^[a-zA-Z0-9_-]+$/', $code) !== 1) {
            return 'default';
        }

        return $code;
    }

    /**
     * Read the cached 404 page for a website×locale, walking the language fallback chain.
     *
     * @return array{body: string, path: string, lang: string}|null
     */
    public static function read(string $websiteCode, string $lang): ?array
    {
        foreach (StorefrontLocale::langFallbackChain($lang) as $candidate) {
            $path = self::filePath($websiteCode, $candidate);
            if (!\is_file($path) || !\is_readable($path)) {
                continue;
            }
            $body = @\file_get_contents($path);
            if (!\is_string($body) || $body === '') {
                continue;
            }
            if (!self::verifyOk($path, $body)) {
                // Incomplete / corrupted leaf: self-heal instead of serving partial HTML.
                @\unlink($path);
                @\unlink($path . '.ok');
                continue;
            }

            return ['body' => $body, 'path' => $path, 'lang' => $candidate];
        }

        return null;
    }

    public static function publish(string $websiteCode, string $lang, string $html): bool
    {
        $html = (string)$html;
        if (\trim($html) === '') {
            return false;
        }
        if (\class_exists(HtmlCacheAdmission::class) && !HtmlCacheAdmission::admitDocument($html)) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    '[StorefrontNotFoundFpcStore] publish refused: incomplete document ('
                    . HtmlCacheAdmission::documentIntegrityIssue($html)
                    . '), bytes=' . \strlen($html) . ' for ' . $websiteCode . '/' . $lang
                );
            }

            return false;
        }

        $path = self::filePath($websiteCode, $lang);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $html) === false) {
            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            return false;
        }
        @\file_put_contents($path . '.ok', self::okToken($html));

        return true;
    }

    public static function purge(?string $websiteCode = null, ?string $lang = null): void
    {
        $root = self::root();
        if ($websiteCode !== null && $websiteCode !== '') {
            $code = self::sanitizeCode($websiteCode);
            if ($lang !== null && $lang !== '') {
                $path = self::filePath($code, $lang);
                @\unlink($path);
                @\unlink($path . '.ok');

                return;
            }
            self::removeTree($root . \DIRECTORY_SEPARATOR . $code);

            return;
        }

        self::removeTree($root);
    }

    /**
     * Single-flight build. When another worker holds the lock this returns the
     * current cached body (or null) instead of rendering a duplicate page.
     *
     * @param callable(): ?string $build
     */
    public static function withBuildLock(string $websiteCode, string $lang, callable $build): ?string
    {
        $root = self::root();
        if (!\is_dir($root) && !@\mkdir($root, 0775, true) && !\is_dir($root)) {
            return $build();
        }

        $lockPath = $root . \DIRECTORY_SEPARATOR . '.lock.'
            . self::sanitizeCode($websiteCode) . '.'
            . (StorefrontLocale::normalizeLangCode($lang) ?: StorefrontLocale::DEFAULT_LANG);
        $handle = @\fopen($lockPath, 'c');
        if ($handle === false) {
            return $build();
        }

        try {
            if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                // Peer is building: give it a beat, then serve whatever landed.
                \usleep(60000);
                $hit = self::read($websiteCode, $lang);

                return $hit !== null ? $hit['body'] : null;
            }

            try {
                // Double-check under lock: a peer may have filled it just before us.
                $hit = self::read($websiteCode, $lang);
                if ($hit !== null) {
                    return $hit['body'];
                }

                return $build();
            } finally {
                @\flock($handle, \LOCK_UN);
            }
        } finally {
            @\fclose($handle);
        }
    }

    private static function okToken(string $html): string
    {
        return 'v1:' . \strlen($html) . ':' . \crc32($html);
    }

    private static function verifyOk(string $path, string $body): bool
    {
        $okPath = $path . '.ok';
        if (!\is_file($okPath)) {
            // Legacy leaves without a marker are accepted (plaintext is authoritative).
            return true;
        }
        $token = \trim((string)@\file_get_contents($okPath));

        return $token === self::okToken($body);
    }

    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = @\scandir($dir);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path)) {
                self::removeTree($path);
                continue;
            }
            @\unlink($path);
        }
        @\rmdir($dir);
    }
}
