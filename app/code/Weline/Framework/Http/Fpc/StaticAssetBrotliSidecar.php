<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\Http\ContentEncodingNegotiator;

/**
 * Writes `$plain.br` next to published compressible static leaves (CSS/JS/…).
 * Disk HIT authority for br clients; plaintext remains for origin gzip/identity.
 * Fail-open: missing brotli / tiny files / I/O errors never throw to callers.
 */
final class StaticAssetBrotliSidecar
{
    public const MIN_BYTES = 1024;

    /** @var list<string> */
    private const EXTENSIONS = [
        'css',
        'js',
        'mjs',
        'map',
        'svg',
        'json',
        'xml',
        'txt',
    ];

    public static function isEligiblePath(string $plainAbsolutePath): bool
    {
        $path = \str_replace('\\', '/', $plainAbsolutePath);
        if ($path === '' || \str_ends_with(\strtolower($path), '.br')) {
            return false;
        }
        $ext = \strtolower((string)\pathinfo($path, \PATHINFO_EXTENSION));

        return $ext !== '' && \in_array($ext, self::EXTENSIONS, true);
    }

    /**
     * Create or refresh `$plainAbsolutePath.br` from current plaintext bytes.
     * Removes stale `.br` when the leaf is ineligible / too small / brotli unavailable.
     */
    public static function publish(string $plainAbsolutePath): bool
    {
        $plain = \str_replace('\\', '/', $plainAbsolutePath);
        $brPath = $plain . '.br';
        if (!self::isEligiblePath($plain) || !\is_file($plain) || !\is_readable($plain)) {
            self::unlinkQuiet($brPath);

            return false;
        }
        $size = @\filesize($plain);
        if (!\is_int($size) || $size < self::MIN_BYTES) {
            self::unlinkQuiet($brPath);

            return false;
        }
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::unlinkQuiet($brPath);

            return false;
        }
        $bytes = @\file_get_contents($plain);
        if (!\is_string($bytes) || $bytes === '') {
            return false;
        }
        $compressed = ContentEncodingNegotiator::encode(
            $bytes,
            ContentEncodingNegotiator::ENCODING_BROTLI,
        );
        if (!\is_string($compressed) || $compressed === '') {
            return false;
        }
        if (\is_file($brPath)) {
            $existing = @\file_get_contents($brPath);
            if (\is_string($existing) && $existing === $compressed) {
                return true;
            }
        }

        return self::writeAtomic($brPath, $compressed);
    }

    public static function unlinkSidecar(string $plainAbsolutePath): void
    {
        self::unlinkQuiet(\str_replace('\\', '/', $plainAbsolutePath) . '.br');
    }

    private static function writeAtomic(string $path, string $bytes): bool
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.tmp-br-' . \bin2hex(\random_bytes(8));
        if (@\file_put_contents($tmp, $bytes, \LOCK_EX) === false) {
            @\unlink($tmp);

            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            return false;
        }

        return true;
    }

    private static function unlinkQuiet(string $path): void
    {
        if ($path !== '' && \is_file($path)) {
            @\unlink($path);
        }
    }
}
