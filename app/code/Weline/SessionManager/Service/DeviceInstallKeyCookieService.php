<?php

declare(strict_types=1);

namespace Weline\SessionManager\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\SessionManager\Api\DeviceInstallKeyProviderInterface;

/**
 * Long-lived browser-profile device key (not a hardware serial).
 * Survives logout; cleared only when the browser drops the cookie.
 */
final class DeviceInstallKeyCookieService implements DeviceInstallKeyProviderInterface
{
    private const COOKIE_FRONTEND = 'w_frontend_dk';
    private const COOKIE_BACKEND = 'w_backend_dk';
    private const LIFETIME_SECONDS = 63072000; // ~2 years

    public function ensure(string $area): array
    {
        $cookieBase = $this->cookieBaseName($area);
        $cookieName = SessionCookieNameResolver::resolveFor($cookieBase);
        $existing = trim((string)Cookie::get($cookieName, ''));
        if ($this->isValidRaw($existing)) {
            $this->writeCookie($cookieName, $existing);
            return [
                'raw' => $existing,
                'digest' => hash('sha256', $existing),
            ];
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->writeCookie($cookieName, $raw);
        return [
            'raw' => $raw,
            'digest' => hash('sha256', $raw),
        ];
    }

    private function cookieBaseName(string $area): string
    {
        return match (strtolower(trim($area))) {
            'backend', 'rest_backend' => self::COOKIE_BACKEND,
            default => self::COOKIE_FRONTEND,
        };
    }

    private function isValidRaw(string $raw): bool
    {
        return $raw !== '' && strlen($raw) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/', $raw) === 1;
    }

    private function writeCookie(string $cookieName, string $raw): void
    {
        $secure = \w_env('server.https') === 'on';
        Cookie::set(
            $cookieName,
            $raw,
            self::LIFETIME_SECONDS,
            [
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => SessionCookieNameResolver::resolveSameSite($secure),
            ],
        );
    }
}
