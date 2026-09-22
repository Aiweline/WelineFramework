<?php

namespace Weline\Framework\Security;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\System\Text;

class Token extends Text
{
    /**
     * @param string $name token名
     * @param int $lenght token长度
     * @param int $lifetime  token有效期（秒）
     * @return string
     */
    public static function create(string $name, int $lenght = 32, int $lifetime = 600)
    {
        // Anonymous storefront GET/HEAD must stay Set-Cookie-free so Full Page
        // Cache can publish. Session-backed CSRF is hydrated only after a jar exists
        // (see Frontend header GuestStorefrontFpcContract).
        if (!self::mayPersistInSession()) {
            return '';
        }

        if ($token = self::get($name)) {
            $session = self::session();
            $session->set($name . '_expired_time', time() + $lifetime);
            return $token;
        }
        $token = Text::random_string($lenght);
        $session = self::session();
        $session->set($name, $token);
        if (0 === $lifetime) {
            $lifetime = $session->getGcMaxLifeTime();
        }
        $session->set($name . '_expired_time', time() + $lifetime);
        return $token;
    }

    public static function session(): Session
    {
        return ObjectManager::getInstance(Session::class);
    }

    public static function get(string $name): ?string
    {
        if (!self::mayPersistInSession()) {
            return null;
        }

        $session = self::session();
        $name_expired_time = (int) $session->get($name . '_expired_time');
        if ($name_expired_time <= 0) {
            return null;
        }
        if ((time() - $name_expired_time) > 0) {
            return null;
        }
        $value = $session->get($name);
        return \is_string($value) ? $value : null;
    }

    /**
     * Whether Token may touch Session storage for the current request.
     *
     * Cookieless frontend document GETs must not allocate WELINE_CUSTOMER_SESSID
     * (and sibling Expire lines) — FullPageCacheCoordinator refuses Set-Cookie.
     */
    public static function mayPersistInSession(): bool
    {
        try {
            $area = \strtolower(\trim((string)(
                WelineEnv::get('area', '')
                ?: WelineEnv::server('WELINE_AREA', '')
                ?: 'frontend'
            )));
            if ($area !== '' && $area !== 'frontend') {
                return true;
            }

            $method = \strtoupper(\trim((string)(
                WelineEnv::server('REQUEST_METHOD', '')
                ?: 'GET'
            )));
            if ($method !== '' && !\in_array($method, ['GET', 'HEAD'], true)) {
                return true;
            }

            return SessionCookieNameResolver::hasRequestCookie('frontend');
        } catch (\Throwable) {
            // Incomplete request context: keep legacy Session Token behavior.
            return true;
        }
    }
}
