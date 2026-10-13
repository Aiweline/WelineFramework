<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;

/**
 * Install-scoped FPC diagnostic pierce stamp.
 *
 * Public query names (_wb_nc / nocache / …) stay stable for open-source docs;
 * without this secret (cookie or query) they must not storm-MISS or edge-bypass.
 */
final class FpcPierceStamp
{
    public const ENV_KEY = 'wls.fpc_pierce_secret';
    public const COOKIE_NAME = 'w_weline_fpc_pierce';
    public const QUERY_NAME = 'w_fpc_pierce';
    public const SECRET_BYTES = 32;
    public const RELATIVE_SECRET_FILE = 'generated/framework/fpc_pierce_secret';

    private static ?string $memoSecret = null;

    public static function clearMemo(): void
    {
        self::$memoSecret = null;
    }

    public static function secretFilePath(): string
    {
        return \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::RELATIVE_SECRET_FILE);
    }

    public static function ensureSecret(): string
    {
        $existing = self::readSecret();
        if ($existing !== '') {
            return $existing;
        }
        $generated = \bin2hex(\random_bytes(self::SECRET_BYTES));
        self::persistSecret($generated);

        return $generated;
    }

    public static function rotateSecret(): string
    {
        $generated = \bin2hex(\random_bytes(self::SECRET_BYTES));
        self::persistSecret($generated);

        return $generated;
    }

    public static function readSecret(): string
    {
        if (self::$memoSecret !== null) {
            return self::$memoSecret;
        }
        $fromFile = self::readSecretFile();
        if ($fromFile !== '') {
            return self::$memoSecret = $fromFile;
        }
        try {
            $fromEnv = \trim((string)Env::get(self::ENV_KEY, ''));
        } catch (\Throwable) {
            $fromEnv = '';
        }
        if ($fromEnv !== '' && self::isHexSecret($fromEnv)) {
            return self::$memoSecret = \strtolower($fromEnv);
        }

        return '';
    }

    public static function persistSecret(string $secret): void
    {
        $secret = \strtolower(\trim($secret));
        if (!self::isHexSecret($secret)) {
            throw new \InvalidArgumentException('FPC pierce secret must be lowercase hex.');
        }
        $path = self::secretFilePath();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory for FPC pierce secret.');
        }
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.tmp-pierce-' . \bin2hex(\random_bytes(6));
        if (@\file_put_contents($tmp, $secret . "\n", \LOCK_EX) === false) {
            @\unlink($tmp);
            throw new \RuntimeException('Cannot write FPC pierce secret temp file.');
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('Cannot publish FPC pierce secret file.');
        }
        @\chmod($path, 0600);
        try {
            Env::set(self::ENV_KEY, $secret);
        } catch (\Throwable) {
            // File is authoritative for Workers; env mirror is best-effort.
        }
        self::$memoSecret = $secret;
    }

    public static function isValidRequest(
        ?string $cookieHeader = null,
        ?array $query = null,
    ): bool {
        $secret = self::readSecret();
        if ($secret === '') {
            return false;
        }
        $candidates = [];
        $fromQuery = self::extractQueryStamp($query);
        if ($fromQuery !== '') {
            $candidates[] = $fromQuery;
        }
        $fromCookie = self::extractCookieStamp($cookieHeader);
        if ($fromCookie !== '') {
            $candidates[] = $fromCookie;
        }
        foreach ($candidates as $candidate) {
            if (\hash_equals($secret, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function isValidCurrentRequest(): bool
    {
        $query = [];
        try {
            $qs = (string)WelineEnv::server('QUERY_STRING', '');
            if ($qs !== '') {
                \parse_str($qs, $query);
            }
            $get = WelineEnv::getGet(null, []);
            if (\is_array($get) && $get !== []) {
                $query = \array_merge($query, $get);
            }
        } catch (\Throwable) {
            $query = [];
        }
        $cookie = '';
        try {
            $cookie = (string)(WelineEnv::server('HTTP_COOKIE', '') ?: WelineEnv::get('server.http_cookie', ''));
        } catch (\Throwable) {
            $cookie = '';
        }

        return self::isValidRequest($cookie, $query);
    }

    /**
     * @return array{cookie:string, query:string, name:string}
     */
    public static function carriageNames(): array
    {
        return [
            'cookie' => self::COOKIE_NAME,
            'query' => self::QUERY_NAME,
            'name' => self::QUERY_NAME,
        ];
    }

    public static function buildQuerySuffix(string $secret = ''): string
    {
        $secret = $secret !== '' ? $secret : self::ensureSecret();

        return self::QUERY_NAME . '=' . \rawurlencode($secret);
    }

    private static function readSecretFile(): string
    {
        $path = self::secretFilePath();
        if (!\is_file($path)) {
            return '';
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw)) {
            return '';
        }
        $secret = \strtolower(\trim($raw));

        return self::isHexSecret($secret) ? $secret : '';
    }

    private static function isHexSecret(string $secret): bool
    {
        $len = \strlen($secret);

        return $len >= 32 && $len <= 128 && \ctype_xdigit($secret);
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private static function extractQueryStamp(?array $query): string
    {
        if (!\is_array($query)) {
            return '';
        }
        $raw = $query[self::QUERY_NAME] ?? null;
        if (\is_array($raw)) {
            return '';
        }

        return \strtolower(\trim((string)$raw));
    }

    private static function extractCookieStamp(?string $cookieHeader): string
    {
        $header = (string)$cookieHeader;
        if ($header === '') {
            return '';
        }
        foreach (\explode(';', $header) as $part) {
            $part = \trim($part);
            if ($part === '') {
                continue;
            }
            $eq = \strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $name = \trim(\substr($part, 0, $eq));
            if (\strcasecmp($name, self::COOKIE_NAME) !== 0) {
                continue;
            }
            $value = \trim(\substr($part, $eq + 1));

            return \strtolower(\rawurldecode($value));
        }

        return '';
    }
}
