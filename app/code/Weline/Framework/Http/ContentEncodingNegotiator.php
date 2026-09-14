<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * HTTP content-coding negotiation: prefer Brotli, fall back to gzip.
 */
final class ContentEncodingNegotiator
{
    public const ENCODING_BROTLI = 'br';
    public const ENCODING_GZIP = 'gzip';

    /** Publish / on-the-fly quality aligned with gzip level 6 (speed vs size). */
    public const BROTLI_QUALITY = 5;

    public static function brotliAvailable(): bool
    {
        return \function_exists('brotli_compress');
    }

    public static function gzipAvailable(): bool
    {
        return \function_exists('gzencode');
    }

    /**
     * Pick the best supported coding for the client Accept-Encoding header.
     * Prefer br over gzip when both are acceptable at equal or higher quality.
     */
    public static function negotiate(string $acceptEncoding): ?string
    {
        $br = self::codingQuality($acceptEncoding, self::ENCODING_BROTLI);
        $gzip = self::codingQuality($acceptEncoding, self::ENCODING_GZIP);

        $brOk = $br > 0.0 && self::brotliAvailable();
        $gzipOk = $gzip > 0.0 && self::gzipAvailable();

        if ($brOk && (!$gzipOk || $br >= $gzip)) {
            return self::ENCODING_BROTLI;
        }
        if ($gzipOk) {
            return self::ENCODING_GZIP;
        }

        return null;
    }

    public static function accepts(string $acceptEncoding, string $encoding): bool
    {
        return self::codingQuality($acceptEncoding, $encoding) > 0.0;
    }

    public static function encode(string $body, string $encoding): ?string
    {
        if ($body === '') {
            return null;
        }

        if ($encoding === self::ENCODING_BROTLI) {
            if (!self::brotliAvailable()) {
                return null;
            }
            $compressed = \brotli_compress($body, self::BROTLI_QUALITY);

            return \is_string($compressed) && $compressed !== '' ? $compressed : null;
        }

        if ($encoding === self::ENCODING_GZIP) {
            if (!self::gzipAvailable()) {
                return null;
            }
            $compressed = \gzencode($body, 6);

            return \is_string($compressed) && $compressed !== '' ? $compressed : null;
        }

        return null;
    }

    public static function decode(string $body, string $encoding): ?string
    {
        if ($body === '') {
            return null;
        }

        if ($encoding === self::ENCODING_BROTLI) {
            if (!\function_exists('brotli_uncompress')) {
                return null;
            }
            $plain = \brotli_uncompress($body);

            return \is_string($plain) ? $plain : null;
        }

        if ($encoding === self::ENCODING_GZIP) {
            if (!\function_exists('gzdecode')) {
                return null;
            }
            $plain = \gzdecode($body);

            return \is_string($plain) ? $plain : null;
        }

        return null;
    }

    public static function isCompressibleContentType(string $contentType): bool
    {
        $contentType = \strtolower(\trim($contentType));
        if ($contentType === '') {
            return true;
        }

        return \str_starts_with($contentType, 'text/')
            || \str_contains($contentType, 'application/json')
            || \str_contains($contentType, 'application/javascript')
            || \str_contains($contentType, 'application/xml')
            || \str_contains($contentType, 'application/xhtml+xml')
            || \str_contains($contentType, 'image/svg+xml');
    }

    private static function codingQuality(string $acceptEncoding, string $coding): float
    {
        $acceptEncoding = \strtolower(\trim($acceptEncoding));
        $coding = \strtolower(\trim($coding));
        if ($acceptEncoding === '' || $coding === '') {
            return 0.0;
        }

        $specificity = -1;
        $quality = 0.0;
        foreach (\explode(',', $acceptEncoding) as $range) {
            $parts = \array_map('trim', \explode(';', $range));
            $token = (string)\array_shift($parts);
            $candidateSpecificity = $token === $coding ? 1 : ($token === '*' ? 0 : -1);
            if ($candidateSpecificity < 0 || $candidateSpecificity < $specificity) {
                continue;
            }

            $candidateQuality = 1.0;
            foreach ($parts as $parameter) {
                if (\preg_match('/^q\s*=\s*(.*)$/D', $parameter, $matches) !== 1) {
                    continue;
                }
                $qValue = (string)($matches[1] ?? '');
                $candidateQuality = \preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?|\.\d{1,3})$/D', $qValue) === 1
                    ? (float)$qValue
                    : 0.0;
                break;
            }

            if ($candidateSpecificity > $specificity) {
                $specificity = $candidateSpecificity;
                $quality = $candidateQuality;
                continue;
            }
            $quality = \max($quality, $candidateQuality);
        }

        return $quality;
    }
}
