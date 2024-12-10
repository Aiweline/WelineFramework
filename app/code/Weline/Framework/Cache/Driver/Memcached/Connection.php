<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Driver\Memcached;

use Memcached;

class Connection
{
    private ?Memcached $memcached;

    private static Connection $connection;

    private function __clone()
    {
    }

    private function __construct($host, $port, $time_out, array $options = [])
    {
        $this->memcached = new Memcached();
        $this->memcached->addServer($host, $port, $time_out);
        if ($options) {
            $this->memcached->setOptions($options);
        }
    }

    public static function getInstance(string $host, int $port, int $time_out, array $options): Connection
    {
        if (!isset(self::$connection)) {
            self::$connection = new self($host, $port, $time_out, $options);
        }
        return self::$connection;
    }

    public function setData($key, $var): static
    {
        $this->memcached->set($key, $var);
        return $this;
    }

    public function getData($key)
    {
        return $this->memcached->get($key);
    }

    public function getMemcached(): ?Memcached
    {
        return $this->memcached;
    }
}
