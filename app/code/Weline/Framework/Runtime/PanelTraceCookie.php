<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Env\WelineEnv;

/**
 * Tiny panel-trace / tpl-perf cookie helpers.
 *
 * Kept separate from {@see RequestLifecycleTrace} so {@see TraceGate} can peek
 * cookies at request bootstrap without autoloading the heavy recorder.
 *
 * @internal
 */
final class PanelTraceCookie
{
    public const PANEL_COOKIE = 'w_weline_trace_panel';
    public const PANEL_PAYLOAD = 'on';
    public const TPL_PERF_COOKIE = 'w_weline_tpl_perf';
    public const TPL_PERF_PAYLOAD = 'on';

    public static function panelCookieName(): string
    {
        return self::PANEL_COOKIE;
    }

    public static function tplPerfCookieName(): string
    {
        return self::TPL_PERF_COOKIE;
    }

    public static function isPanelArmed(): bool
    {
        return self::isValid(self::read(self::PANEL_COOKIE), self::PANEL_PAYLOAD, self::panelSigningKey());
    }

    public static function isTplPerfArmed(): bool
    {
        return self::isValid(self::read(self::TPL_PERF_COOKIE), self::TPL_PERF_PAYLOAD, self::tplPerfSigningKey());
    }

    public static function buildPanelValue(): string
    {
        return self::build(self::PANEL_PAYLOAD, self::panelSigningKey());
    }

    public static function buildTplPerfValue(): string
    {
        return self::build(self::TPL_PERF_PAYLOAD, self::tplPerfSigningKey());
    }

    public static function isValidPanelValue(string $cookieValue): bool
    {
        return self::isValid($cookieValue, self::PANEL_PAYLOAD, self::panelSigningKey());
    }

    public static function isValidTplPerfValue(string $cookieValue): bool
    {
        return self::isValid($cookieValue, self::TPL_PERF_PAYLOAD, self::tplPerfSigningKey());
    }

    private static function build(string $payload, string $key): string
    {
        return self::base64UrlEncode($payload . '.' . \hash_hmac('sha256', $payload, $key));
    }

    private static function isValid(string $cookieValue, string $payload, string $key): bool
    {
        if ($cookieValue === '') {
            return false;
        }
        $decoded = self::base64UrlDecode($cookieValue);
        if ($decoded === '') {
            return false;
        }
        $parts = \explode('.', $decoded, 2);
        if (\count($parts) !== 2) {
            return false;
        }
        [$gotPayload, $signature] = $parts;
        if ($gotPayload !== $payload || $signature === '') {
            return false;
        }

        return \hash_equals(\hash_hmac('sha256', $payload, $key), $signature);
    }

    private static function read(string $cookieName): string
    {
        $candidates = self::candidateCookieNames($cookieName);

        // Prefer already-parsed cookies — avoids loading Cookie helper on closed panel.
        foreach ($candidates as $name) {
            if (isset($_COOKIE[$name]) && (string)$_COOKIE[$name] !== '') {
                return (string)$_COOKIE[$name];
            }
        }

        if (\class_exists(\Weline\Framework\Http\Cookie::class, false)) {
            try {
                $fromHelper = \Weline\Framework\Http\Cookie::get($cookieName, '');
                if (\is_scalar($fromHelper) && (string)$fromHelper !== '') {
                    return (string)$fromHelper;
                }
            } catch (\Throwable) {
            }
        }

        $fromServer = '';
        if (\class_exists(WelineEnv::class, false)) {
            $fromServer = (string)WelineEnv::server('HTTP_COOKIE', '');
        }
        if ($fromServer === '' && isset($_SERVER['HTTP_COOKIE'])) {
            $fromServer = (string)$_SERVER['HTTP_COOKIE'];
        }
        if ($fromServer !== '') {
            $wanted = [];
            foreach ($candidates as $name) {
                $wanted[$name] = true;
            }
            foreach (\explode(';', $fromServer) as $pair) {
                $pair = \trim($pair);
                if ($pair === '' || !\str_contains($pair, '=')) {
                    continue;
                }
                [$name, $value] = \explode('=', $pair, 2);
                $name = \trim($name);
                if ($name !== '' && isset($wanted[$name])) {
                    return \rawurldecode(\trim($value));
                }
            }
        }

        return '';
    }

    /**
     * Logical name plus CookieScope-qualified wire name (e.g. w_weline_trace_panel_w0).
     *
     * CookieScope is used only when already loaded (no autoload on the closed-panel
     * hot path). Before CookieScope exists, a single `{name}_*` alias in $_COOKIE is
     * accepted so TraceGate can arm from WLS worker-scoped Set-Cookie; ambiguous
     * multi-suffix bags wait until CookieScope can pick the active worker.
     *
     * @return list<string>
     */
    private static function candidateCookieNames(string $cookieName): array
    {
        $names = [$cookieName];
        if (\class_exists(\Weline\Framework\Http\CookieScope::class, false)) {
            try {
                $qualified = \Weline\Framework\Http\CookieScope::qualifyName($cookieName);
                if ($qualified !== '' && $qualified !== $cookieName) {
                    $names[] = $qualified;

                    return $names;
                }
            } catch (\Throwable) {
            }
            // Isolation inactive (identity qualify): fall through to sole-alias peek.
        }

        $aliases = [];
        foreach ($_COOKIE as $name => $value) {
            if (!\is_string($name) || (string)$value === '') {
                continue;
            }
            if ($name !== $cookieName && \str_starts_with($name, $cookieName . '_')) {
                $aliases[] = $name;
            }
        }
        if (\count($aliases) === 1) {
            $names[] = $aliases[0];
        }

        return $names;
    }

    private static function panelSigningKey(): string
    {
        $salt = \defined('BP') ? (string)BP : __DIR__;

        return \hash('sha256', $salt . '|request_lifecycle_trace_panel');
    }

    private static function tplPerfSigningKey(): string
    {
        $salt = \defined('BP') ? (string)BP : __DIR__;

        return \hash('sha256', $salt . '|request_lifecycle_tpl_perf_panel');
    }

    private static function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $value = \strtr($value, '-_', '+/');
        $padding = \strlen($value) % 4;
        if ($padding > 0) {
            $value .= \str_repeat('=', 4 - $padding);
        }
        $decoded = \base64_decode($value, true);

        return \is_string($decoded) ? $decoded : '';
    }
}
