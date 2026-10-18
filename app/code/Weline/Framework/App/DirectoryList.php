<?php

declare(strict_types=1);

namespace Weline\Framework\App;

/**
 * Relocatable filesystem roots from env.php `directories` (pub / var / media).
 *
 * Bootstrap MUST call {@see self::defineBootstrapConstants()} before Env class
 * constants that historically depended on PUB, and before any runtime I/O under
 * pub/var/media. Empty / absent config keeps BP defaults.
 */
final class DirectoryList
{
    private string $pub;
    private string $var;
    private string $media;

    private function __construct(string $pub, string $var, string $media)
    {
        $this->pub = $pub;
        $this->var = $var;
        $this->media = $media;
    }

    /**
     * Read app/etc/env.php directories only (Env singleton may not exist yet).
     */
    public static function bootstrapFromEnvFile(string $bp): self
    {
        $bp = self::normalizeRoot($bp);
        $ds = \DIRECTORY_SEPARATOR;
        $config = self::readDirectoriesConfig($bp);

        $pub = self::resolveRoot($bp, (string)($config['pub'] ?? ''), $bp . $ds . 'pub');
        $var = self::resolveRoot($bp, (string)($config['var'] ?? ''), $bp . $ds . 'var');
        $mediaDefault = \rtrim($pub, '/\\') . $ds . 'media';
        $media = self::resolveRoot($bp, (string)($config['media'] ?? ''), $mediaDefault);

        return new self(
            self::withTrailingSep($pub),
            self::withTrailingSep($var),
            self::withTrailingSep($media),
        );
    }

    /**
     * Define PUB / VAR_DIR / MEDIA_DIR once. Safe to call from App::init and WlsRuntime.
     */
    public static function defineBootstrapConstants(?string $bp = null): self
    {
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        $bp = $bp ?? (\defined('BP') ? (string)\constant('BP') : '');
        if ($bp === '') {
            throw new \RuntimeException('DirectoryList requires BP before bootstrap constants.');
        }
        $list = self::bootstrapFromEnvFile($bp);
        if (!\defined('PUB')) {
            \define('PUB', $list->getPub());
        }
        if (!\defined('VAR_DIR')) {
            \define('VAR_DIR', $list->getVar());
        }
        if (!\defined('MEDIA_DIR')) {
            \define('MEDIA_DIR', $list->getMedia());
        }

        return $list;
    }

    public function getPub(): string
    {
        return $this->pub;
    }

    public function getVar(): string
    {
        return $this->var;
    }

    public function getMedia(): string
    {
        return $this->media;
    }

    /**
     * Resolve cache/session/log style paths.
     * Absolute → as-is; relative starting with var/ → under relocatable VAR root; else under BP.
     */
    public function resolveConfiguredDataPath(string $configured): string
    {
        $configured = \trim(\str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $configured));
        if ($configured === '') {
            throw new \InvalidArgumentException('Configured data path must not be empty.');
        }
        if (self::isAbsolutePath($configured)) {
            return self::withTrailingSepIfDirHint($configured);
        }
        $normalized = \ltrim($configured, '/\\');
        $lower = \strtolower(\str_replace('\\', '/', $normalized));
        if (\str_starts_with($lower, 'var/') || $lower === 'var') {
            $suffix = $lower === 'var' ? '' : \substr($normalized, 4);
            $suffix = \str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $suffix);
            $base = \rtrim($this->var, '/\\');
            return $suffix === ''
                ? self::withTrailingSep($base)
                : self::withTrailingSepIfDirHint($base . \DIRECTORY_SEPARATOR . $suffix);
        }
        $bp = \defined('BP') ? \rtrim((string)\constant('BP'), '/\\') : '';
        return self::withTrailingSepIfDirHint($bp . \DIRECTORY_SEPARATOR . $normalized);
    }

    public static function resolveConfiguredDataPathStatic(string $configured): string
    {
        $bp = \defined('BP') ? (string)\constant('BP') : '';
        return self::bootstrapFromEnvFile($bp)->resolveConfiguredDataPath($configured);
    }

    /**
     * Map URL path under /pub/media or /media to absolute file under MEDIA_DIR.
     */
    public static function mediaAbsoluteFromUrlPath(string $urlPath): string
    {
        $urlPath = \str_replace('\\', '/', $urlPath);
        $urlPath = \rawurldecode($urlPath);
        if (\str_contains($urlPath, '..') || \preg_match('/[\x00-\x1F\x7F]/', $urlPath)) {
            throw new \InvalidArgumentException('Invalid media URL path.');
        }
        $rel = $urlPath;
        if (\str_starts_with($rel, '/pub/media/')) {
            $rel = \substr($rel, \strlen('/pub/media/'));
        } elseif (\str_starts_with($rel, 'pub/media/')) {
            $rel = \substr($rel, \strlen('pub/media/'));
        } elseif (\str_starts_with($rel, '/media/')) {
            $rel = \substr($rel, \strlen('/media/'));
        } elseif (\str_starts_with($rel, 'media/')) {
            $rel = \substr($rel, \strlen('media/'));
        }
        $rel = \ltrim($rel, '/');
        $mediaRoot = \defined('MEDIA_DIR')
            ? \rtrim((string)\constant('MEDIA_DIR'), '/\\')
            : \rtrim((\defined('PUB') ? (string)\constant('PUB') : ''), '/\\') . \DIRECTORY_SEPARATOR . 'media';
        return $mediaRoot . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);
    }

    /**
     * @return array{pub?: mixed, var?: mixed, media?: mixed}
     */
    private static function readDirectoriesConfig(string $bp): array
    {
        $envFile = \rtrim($bp, '/\\') . \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
        if (!\is_file($envFile) || !\is_readable($envFile)) {
            return [];
        }
        /** @var mixed $config */
        $config = include $envFile;
        if (!\is_array($config)) {
            return [];
        }
        $directories = $config['directories'] ?? null;
        return \is_array($directories) ? $directories : [];
    }

    private static function resolveRoot(string $bp, string $configured, string $default): string
    {
        $configured = \trim($configured);
        if ($configured === '') {
            return self::normalizeRoot($default);
        }
        if (\str_contains($configured, '..')) {
            throw new \InvalidArgumentException('directories path must not contain "..".');
        }
        $candidate = self::isAbsolutePath($configured)
            ? $configured
            : \rtrim($bp, '/\\') . \DIRECTORY_SEPARATOR . \ltrim(\str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $configured), '/\\');
        $normalized = self::normalizeRoot($candidate);
        if ($normalized === '' || self::isFilesystemRootOnly($normalized)) {
            throw new \InvalidArgumentException('directories path resolves to an invalid root.');
        }
        return $normalized;
    }

    private static function normalizeRoot(string $path): string
    {
        $path = \str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, \trim($path));
        return \rtrim($path, '/\\');
    }

    private static function withTrailingSep(string $path): string
    {
        $path = self::normalizeRoot($path);
        return $path . \DIRECTORY_SEPARATOR;
    }

    private static function withTrailingSepIfDirHint(string $path): string
    {
        $endsWithSep = \str_ends_with($path, '/') || \str_ends_with($path, '\\');
        $normalized = self::normalizeRoot($path);
        return $endsWithSep ? $normalized . \DIRECTORY_SEPARATOR : $normalized;
    }

    public static function isAbsolutePath(string $path): bool
    {
        $path = \str_replace('\\', '/', $path);
        if (\str_starts_with($path, '/')) {
            return true;
        }
        return \preg_match('/^[A-Za-z]:[\\/]/', $path) === 1;
    }

    private static function isFilesystemRootOnly(string $path): bool
    {
        $trimmed = \rtrim($path, '/\\');
        return $trimmed === '' || \preg_match('/^[A-Za-z]:$/D', $trimmed) === 1;
    }
}
