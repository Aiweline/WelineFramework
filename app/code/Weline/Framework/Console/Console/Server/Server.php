<?php

namespace Weline\Framework\Console\Console\Server;

class Server {
    static function instance(
        string $host = '127.0.0.1',
        int $port = 9981,
    )
    {
        # 启动PHP内置web服务器
        $command = PHP_BINARY . ' -S ' . $host . ':' . $port . ' -t ' . PUB.' ' .PUB. 'index.php';
//        dd($command);
        exec($command);
    }
}