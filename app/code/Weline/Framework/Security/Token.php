<?php

namespace Weline\Framework\Security;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;
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
            // WLS：过期续期必须立刻落盘，否则页内 token 与下一请求 Token::get 分叉。
            $session->save();
            return $token;
        }
        $token = Text::random_string($lenght);
        $session = self::session();
        $session->set($name, $token);
        if (0 === $lifetime) {
            $lifetime = $session->getGcMaxLifeTime();
        }
        $session->set($name . '_expired_time', time() + $lifetime);
        // 轮换新 CSRF 同步落盘：禁止只写进与登录会话脱钩的 OM Session 内存。
        $session->save();
        return $token;
    }

    /**
     * 必须绑定当前请求 area 的 SessionFactory 会话（与后台登录 jar 同一实例）。
     * 禁止 ObjectManager::getInstance(Session::class)：那是另一份未挂登录 cookie 的会话，
     * create() 写出的 csrf 不会进入 WELINE_SESSID 落盘 → SSE Token::get('csrf')=null。
     */
    public static function session(): Session
    {
        $area = self::resolveRequestArea();
        $factory = ObjectManager::getInstance(SessionFactory::class);
        $session = $factory->createSession($area);
        if ($session instanceof Session) {
            return $session;
        }

        // 极端：工厂返回非 Session 实现时回退（不应发生）。
        return ObjectManager::getInstance(Session::class);
    }

    private static function resolveRequestArea(): string
    {
        $area = \strtolower(\trim((string)(
            WelineEnv::get('area', '')
            ?: WelineEnv::server('WELINE_AREA', '')
            ?: ''
        )));

        // 空 area 时不要默认 frontend：后台 CSRF 必须进 WELINE_SESSID 族。
        // normalizeArea('') 会回落 frontend，导致 Token 写到顾客会话。
        return SessionCookieNameResolver::normalizeArea($area !== '' ? $area : 'backend');
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
