<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;
use Weline\Framework\Http\StorefrontLocale;
use Weline\Framework\View\Helper\HtmlCacheAdmission;

/**
 * Special per-language FPC store for maintenance (503) pages.
 *
 * Mirrors {@see StorefrontNotFoundFpcStore}: the normal FpcHtmlDiskStore refuses
 * error/service documents, so maintenance HTML/JSON lives in its own disk tree,
 * keyed by websiteCode × locale (not request URL). Any request can serve the same
 * stable page while the visitor's URL stays untouched.
 *
 * Layout: generated/error-pages/maintenance/{websiteCode}/{lang}.html|.json (+ .ok)
 *
 * Populated lazily from the Maintenance module's own layout
 * (Maintenance\Service\MaintenanceStaticGenerator::renderHtml/renderJson), never
 * pre-generated during setup/upgrade.
 */
final class MaintenanceFpcStore
{
    public const KIND = 'maintenance';

    public static function root(): string
    {
        $base = \rtrim((string)Env::GENERATED_DIR, '\\/');

        return $base . \DIRECTORY_SEPARATOR . 'error-pages' . \DIRECTORY_SEPARATOR . 'maintenance';
    }

    public static function filePath(string $websiteCode, string $lang, bool $isJson = false): string
    {
        $code = self::sanitizeCode($websiteCode);
        $lang = StorefrontLocale::normalizeLangCode($lang) ?: StorefrontLocale::DEFAULT_LANG;

        return self::root() . \DIRECTORY_SEPARATOR . $code . \DIRECTORY_SEPARATOR . $lang . ($isJson ? '.json' : '.html');
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
     * @return array{body: string, path: string, lang: string}|null
     */
    public static function read(string $websiteCode, string $lang, bool $isJson = false): ?array
    {
        foreach (StorefrontLocale::langFallbackChain($lang) as $candidate) {
            $path = self::filePath($websiteCode, $candidate, $isJson);
            if (!\is_file($path) || !\is_readable($path)) {
                continue;
            }
            $body = @\file_get_contents($path);
            if (!\is_string($body) || $body === '') {
                continue;
            }
            if (!self::verifyOk($path, $body)) {
                @\unlink($path);
                @\unlink($path . '.ok');
                continue;
            }

            return ['body' => $body, 'path' => $path, 'lang' => $candidate];
        }

        return null;
    }

    public static function publish(string $websiteCode, string $lang, string $content, bool $isJson = false): bool
    {
        $content = (string)$content;
        if (\trim($content) === '') {
            return false;
        }
        if (!$isJson && \class_exists(HtmlCacheAdmission::class) && !HtmlCacheAdmission::admitDocument($content)) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    '[MaintenanceFpcStore] publish refused: incomplete document ('
                    . HtmlCacheAdmission::documentIntegrityIssue($content)
                    . '), bytes=' . \strlen($content) . ' for ' . $websiteCode . '/' . $lang
                );
            }

            return false;
        }

        $path = self::filePath($websiteCode, $lang, $isJson);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            return false;
        }
        @\file_put_contents($path . '.ok', self::okToken($content));

        return true;
    }

    public static function purge(?string $websiteCode = null, ?string $lang = null): void
    {
        $root = self::root();
        if ($websiteCode !== null && $websiteCode !== '') {
            $code = self::sanitizeCode($websiteCode);
            if ($lang !== null && $lang !== '') {
                foreach ([false, true] as $isJson) {
                    $path = self::filePath($code, $lang, $isJson);
                    @\unlink($path);
                    @\unlink($path . '.ok');
                }

                return;
            }
            self::removeTree($root . \DIRECTORY_SEPARATOR . $code);

            return;
        }

        self::removeTree($root);
    }

    /**
     * Single-flight build for one website×locale; returns cached body when a peer
     * owns the build, so we never render the same maintenance page twice at once.
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
                \usleep(60000);
                $hit = self::read($websiteCode, $lang);

                return $hit !== null ? $hit['body'] : null;
            }
            try {
                return $build();
            } finally {
                @\flock($handle, \LOCK_UN);
            }
        } finally {
            @\fclose($handle);
        }
    }

    private static function okToken(string $content): string
    {
        return 'v1:' . \strlen($content) . ':' . \crc32($content);
    }

    private static function verifyOk(string $path, string $body): bool
    {
        $okPath = $path . '.ok';
        if (!\is_file($okPath)) {
            return true;
        }

        return \trim((string)@\file_get_contents($okPath)) === self::okToken($body);
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
