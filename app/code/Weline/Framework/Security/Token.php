<?php

namespace Weline\Framework\Security;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
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
}
