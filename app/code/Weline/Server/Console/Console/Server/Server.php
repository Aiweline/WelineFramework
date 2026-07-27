<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

/**
 * 已退役的 PHP 内置 Web 服务器兼容入口。
 */
class Server
{
    public static function instance(
        string $host = '127.0.0.1',
        int $port = 9981,
        bool $backend = false
    ): ?int {
        throw new \RuntimeException((string)__('Nginx 是唯一公网边缘，不能跳过其启动。'));
    }
}
