<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Shared zero-DB path / host-map helpers for website×locale static error pages.
 *
 * Hot path must not touch the database: Host(+subPath) → websiteCode → file.
 */
final class StaticErrorPageMap
{
    public const HOST_MAP_FILENAME = '_host_map.php';

    public const DEFAULT_WEBSITE_CODE = 'default';

    /**
     * Sanitize website code for filesystem use. Empty string means invalid.
     */
    public static function sanitizeWebsiteCode(string $code): string
    {
        $code = \trim($code);
        if ($code === '' || \preg_match('/^[a-zA-Z0-9_-]+$/', $code) !== 1) {
            return '';
        }

        return $code;
    }

    public static function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return '';
        }
        // Strip optional port.
        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            $host = \explode(':', $host, 2)[0];
        }

        return $host;
    }

    /**
     * Normalize sub-path for map keys: leading slash, no trailing slash; empty = host root.
     * Example keys: host or host|/store-a
     */
    public static function normalizeSubPath(string $subPath): string
    {
        $subPath = \trim(\str_replace('\\', '/', $subPath));
        if ($subPath === '' || $subPath === '/') {
            return '';
        }
        if (!\str_starts_with($subPath, '/')) {
            $subPath = '/' . $subPath;
        }

        return \rtrim($subPath, '/') ?: '';
    }

    /**
     * Build map key: host or host|subPath (subPath includes leading /).
     */
    public static function mapKey(string $host, string $subPath = ''): string
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return '';
        }
        $subPath = self::normalizeSubPath($subPath);

        return $subPath === '' ? $host : $host . '|' . $subPath;
    }

    /**
     * Longest-prefix match of request path against host|subPath keys for a host.
     *
     * @param array<string, string> $map
     */
    public static function resolveWebsiteCodeFromMap(array $map, string $host, string $requestPath): string
    {
        $host = self::normalizeHost($host);
        if ($host === '' || $map === []) {
            return '';
        }

        $rawPath = (string)(\parse_url($requestPath, \PHP_URL_PATH) ?: $requestPath);
        $path = self::normalizeSubPath($rawPath);
        // Root request path compares as empty for host-only keys.
        $candidates = [];
        foreach ($map as $key => $code) {
            $key = (string)$key;
            $code = self::sanitizeWebsiteCode((string)$code);
            if ($code === '') {
                continue;
            }
            if ($key === $host) {
                $candidates[] = ['sub' => '', 'code' => $code, 'len' => 0];
                continue;
            }
            $prefix = $host . '|';
            if (!\str_starts_with($key, $prefix)) {
                continue;
            }
            $sub = self::normalizeSubPath(\substr($key, \strlen($prefix)));
            if ($sub === '') {
                continue;
            }
            $pathForMatch = $path === '' ? '/' : $path;
            if ($pathForMatch === $sub || \str_starts_with($pathForMatch . '/', $sub . '/')) {
                $candidates[] = ['sub' => $sub, 'code' => $code, 'len' => \strlen($sub)];
            }
        }

        if ($candidates === []) {
            return '';
        }

        \usort($candidates, static fn(array $a, array $b): int => $b['len'] <=> $a['len']);

        return (string)$candidates[0]['code'];
    }

    /**
     * @return array<string, string>
     */
    public static function loadHostMap(string $directory): array
    {
        $file = \rtrim($directory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . self::HOST_MAP_FILENAME;
        if (!\is_file($file) || !\is_readable($file)) {
            return [];
        }

        try {
            /** @var mixed $data */
            $data = include $file;
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($data)) {
            return [];
        }

        $map = [];
        foreach ($data as $key => $code) {
            $key = \trim((string)$key);
            $code = self::sanitizeWebsiteCode((string)$code);
            if ($key === '' || $code === '') {
                continue;
            }
            $map[$key] = $code;
        }

        return $map;
    }

    /**
     * Atomic write of host map (tmp + rename).
     *
     * @param array<string, string> $map
     */
    public static function writeHostMapAtomic(string $directory, array $map): bool
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            return false;
        }

        $safe = [];
        foreach ($map as $key => $code) {
            $key = \trim((string)$key);
            $code = self::sanitizeWebsiteCode((string)$code);
            if ($key === '' || $code === '') {
                continue;
            }
            $safe[$key] = $code;
        }

        $export = \var_export($safe, true);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by StaticErrorPagePublisher — do not edit.\nreturn {$export};\n";
        $target = \rtrim($directory, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . self::HOST_MAP_FILENAME;
        $tmp = $target . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contents) === false) {
            return false;
        }

        return @\rename($tmp, $target);
    }

    public static function errorsBaseDir(string $kind): string
    {
        $base = \defined('PUB') ? \rtrim((string)PUB, \DIRECTORY_SEPARATOR) : ((\defined('BP') ? BP : '') . 'pub');

        return $base . \DIRECTORY_SEPARATOR . 'errors' . \DIRECTORY_SEPARATOR . $kind;
    }

    /**
     * Website-scoped file path; empty websiteCode uses flat legacy layout.
     */
    public static function websiteLocaleFile(string $kind, string $websiteCode, string $lang, string $suffix = '.html'): string
    {
        $dir = self::errorsBaseDir($kind);
        $code = self::sanitizeWebsiteCode($websiteCode);
        if ($code !== '') {
            return $dir . \DIRECTORY_SEPARATOR . $code . \DIRECTORY_SEPARATOR . $lang . $suffix;
        }

        return $dir . \DIRECTORY_SEPARATOR . $lang . $suffix;
    }

    /**
     * Resolve HTML/JSON content with website×locale fallback chain.
     *
     * Order: {code}/{lang} → default/{lang} → flat {lang}.{ext} for each lang in chain.
     */
    public static function loadWithFallback(
        string $kind,
        string $lang,
        string $host = '',
        string $requestPath = '/',
        bool $isApi = false,
        ?string $explicitWebsiteCode = null,
    ): ?string {
        $suffix = $isApi ? '.json' : '.html';
        $dir = self::errorsBaseDir($kind);
        $websiteCode = $explicitWebsiteCode !== null && $explicitWebsiteCode !== ''
            ? self::sanitizeWebsiteCode($explicitWebsiteCode)
            : '';
        if ($websiteCode === '' && $host !== '') {
            $websiteCode = self::resolveWebsiteCodeFromMap(self::loadHostMap($dir), $host, $requestPath);
        }

        $codes = [];
        if ($websiteCode !== '') {
            $codes[] = $websiteCode;
        }
        if (!\in_array(self::DEFAULT_WEBSITE_CODE, $codes, true)) {
            $codes[] = self::DEFAULT_WEBSITE_CODE;
        }
        // Flat legacy (empty code sentinel).
        $codes[] = '';

        foreach ($codes as $code) {
            foreach (self::langFallbackChain($kind, $lang) as $candidate) {
                $file = self::websiteLocaleFile($kind, $code, $candidate, $suffix);
                if (!\is_file($file) || !\is_readable($file)) {
                    continue;
                }
                $contents = @\file_get_contents($file);
                if (\is_string($contents) && $contents !== '') {
                    return $contents;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function langFallbackChain(string $kind, string $lang): array
    {
        $defaultLang = $kind === 'maintenance'
            ? MaintenanceStaticPage::DEFAULT_LANG
            : StorefrontNotFoundStaticPage::DEFAULT_LANG;

        $chain = [];
        foreach ([$lang, $defaultLang, 'en_US'] as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '' || \in_array($candidate, $chain, true)) {
                continue;
            }
            $chain[] = $candidate;
        }

        return $chain;
    }

    /**
     * Prune website subdirs / locale files not in the active set; keep flat legacy + host map.
     *
     * @param array<string, list<string>> $activeByWebsite websiteCode => locale list
     */
    public static function pruneWebsiteLocales(string $kind, array $activeByWebsite, array $extensions = ['html']): void
    {
        $dir = self::errorsBaseDir($kind);
        if (!\is_dir($dir)) {
            return;
        }

        $activeCodes = [];
        foreach ($activeByWebsite as $code => $locales) {
            $safe = self::sanitizeWebsiteCode((string)$code);
            if ($safe === '') {
                continue;
            }
            $activeCodes[$safe] = \array_fill_keys(\array_map('strval', $locales), true);
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::HOST_MAP_FILENAME || $entry === 'flags') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($path)) {
                $code = self::sanitizeWebsiteCode($entry);
                if ($code === '' || $code !== $entry) {
                    continue;
                }
                if (!isset($activeCodes[$code])) {
                    self::removeDirectory($path);
                    continue;
                }
                foreach (\scandir($path) ?: [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $full = $path . \DIRECTORY_SEPARATOR . $file;
                    if (!\is_file($full)) {
                        continue;
                    }
                    foreach ($extensions as $ext) {
                        $suffix = '.' . $ext;
                        if (\str_ends_with($file, $suffix)) {
                            $lang = \substr($file, 0, -\strlen($suffix));
                            if (!isset($activeCodes[$code][$lang])) {
                                @\unlink($full);
                            }
                            break;
                        }
                    }
                }
                continue;
            }
            // Flat legacy files are retained as hot-path fallback; do not delete here.
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . \DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($full)) {
                self::removeDirectory($full);
            } else {
                @\unlink($full);
            }
        }
        @\rmdir($path);
    }
}
