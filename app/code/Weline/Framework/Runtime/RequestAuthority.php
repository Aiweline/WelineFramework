<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Resolves the canonical public request authority from trusted ingress facts.
 *
 * HTTP_HOST is the primary authority. WELINE_FULL_REQUEST_URI is only a
 * consistency check, or a compatibility fallback when HTTP_HOST is absent.
 * Invalid or conflicting facts fail closed instead of selecting a different
 * host source.
 */
final class RequestAuthority
{
    public static function current(): string
    {
        $context = Context::getCurrent();
        return $context instanceof Context ? self::fromContext($context) : '';
    }

    public static function fromContext(Context $context): string
    {
        $rawIngress = \trim((string)$context->server('HTTP_HOST', ''));
        $ingress = $rawIngress === '' ? '' : self::canonicalize($rawIngress);
        if ($rawIngress !== '' && $ingress === '') {
            return '';
        }

        $rawFullUri = \trim((string)$context->server('WELINE_FULL_REQUEST_URI', ''));
        $frozen = $rawFullUri === '' ? '' : self::authorityFromFullRequestUri($rawFullUri);
        if ($rawFullUri !== '' && $frozen === '') {
            return '';
        }

        if ($ingress !== '' && $frozen !== '' && !\hash_equals($ingress, $frozen)) {
            return '';
        }

        return $ingress !== '' ? $ingress : $frozen;
    }

    public static function canonicalize(string $authority): string
    {
        $authority = \strtolower(\trim($authority));
        if ($authority === ''
            || \strlen($authority) > 261
            || \preg_match('~[\x00-\x20\x7F/\\\\@?#]~', $authority) === 1) {
            return '';
        }

        if (\str_starts_with($authority, '[')) {
            if (\preg_match('/^\[([^]]+)](?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
                return '';
            }
            $packed = @\inet_pton($matches[1]);
            if ($packed === false || \strlen($packed) !== 16) {
                return '';
            }
            $host = \strtolower((string)\inet_ntop($packed));
            $port = self::canonicalPort($matches[2] ?? '');
            if ($port === null) {
                return '';
            }
            return '[' . $host . ']' . ($port === '' ? '' : ':' . $port);
        }

        if (\substr_count($authority, ':') > 1
            || \preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', $authority, $matches) !== 1) {
            return '';
        }

        $hostname = $matches[1];
        if (\str_ends_with($hostname, '..')) {
            return '';
        }
        $hostname = \rtrim($hostname, '.');
        if (!self::isValidHostname($hostname)) {
            return '';
        }

        $port = self::canonicalPort($matches[2] ?? '');
        if ($port === null) {
            return '';
        }
        return $hostname . ($port === '' ? '' : ':' . $port);
    }

    private static function authorityFromFullRequestUri(string $uri): string
    {
        try {
            $parts = \parse_url($uri);
        } catch (\ValueError) {
            return '';
        }
        if (!\is_array($parts)
            || !\in_array(\strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return '';
        }

        $host = $parts['host'];
        if (!\str_starts_with($host, '[')
            && \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        $authority = $host;
        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port < 1 || $port > 65535) {
                return '';
            }
            $authority .= ':' . $port;
        }

        return self::canonicalize($authority);
    }

    private static function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || \strlen($hostname) > 253) {
            return false;
        }
        if (\filter_var($hostname, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (\preg_match('/^[0-9.]+$/D', $hostname) === 1) {
            return false;
        }
        foreach (\explode('.', $hostname) as $label) {
            if (\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function canonicalPort(string $port): ?string
    {
        if ($port === '') {
            return '';
        }
        $number = (int)$port;
        return $number >= 1 && $number <= 65535 ? (string)$number : null;
    }
}
