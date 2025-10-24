<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：30/9/2022 14:50:23
 */

namespace Weline\Framework\Rpc;

class Server
{
    /**
     * 此类的基本配置
     * @var string[]
     */
    private $params = [
        'host' => '',  // ip地址，列出来的目的是为了友好看出来此变量中存储的信息
        'port' => '', // 端口
        'path' => '' // 服务目录
    ];
    /**
     * 本类常用配置
     * @var array
     */
    private $config = [
        'real_path' => '',
        'max_size'  => 2048 // 最大接收数据大小
    ];

    /**
     * @var null
     */
    private mixed $server = null;

    /**
     * @DESC          # 必要验证
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:52
     * 参数区：
     */
    private function check()
    {
        $this->serverPath();
    }

    /**
     * @DESC          # 初始化必要参数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:53
     * 参数区：
     *
     * @param $params
     */
    private function init($params)
    {
        // 将传递过来的参数初始化
        $this->params = $params;
        // 创建tcpsocket服务
        $this->create();
    }

    /**
     * @DESC          # 创建tcpsocket服务
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:53
     * 参数区：
     */
    private function create(): void
    {
        $this->server = stream_socket_server("tcp://{$this->params['host']}:{$this->params['port']}", $errno, $errstr);
        if (!$this->server) {
            exit([
                $errno, $errstr
            ]);
        }
    }

    /**
     * @DESC          # rpc服务目录
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:53
     * 参数区：
     */
    public function serverPath(): void
    {
        $path     = $this->params['path'];
        $realPath = realpath(__DIR__ . $path);
        if ($realPath === false || !file_exists($realPath)) {
            exit("{$path} error!");
        }
        $this->config['real_path'] = $realPath;
    }

    /**
     * @DESC          # 返回当前实例
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:53
     * 参数区：
     *
     * @param $params
     *
     * @return Server
     */
    public static function instance($params): Server
    {
        $server = new Server();
        $server->check();
        $server->init($params);
        return $server;
    }

    /**
     * @DESC          # 运行
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:53
     * 参数区：
     * @return mixed
     */
    public function run(): mixed
    {
        while (true) {
            $client = stream_socket_accept($this->server);
            if ($client) {
                echo "有新连接\n";
                $buf = fread($client, $this->config['max_size']);
                print_r('接收到的原始数据:' . $buf . "\n");
                // 自定义协议目的是拿到类方法和参数(可改成自己定义的)
                $this->parseProtocol($buf, $class, $method, $params);
                // 执行方法
                $this->execMethod($client, $class, $method, $params);
                //关闭客户端
                fclose($client);
                echo "关闭了连接\n";
            }
        }
    }

    /**
     * @DESC          # 执行方法
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:54
     * 参数区：
     *
     * @param $client
     * @param $class
     * @param $method
     * @param $params
     */
    private function execMethod($client, $class, $method, $params): void
    {
        if ($class && $method) {
            // 首字母转为大写
            $class = ucfirst($class);
            $file  = $this->params['path'] . '/' . $class . '.php';
            //判断文件是否存在，如果有，则引入文件
            if (file_exists($file)) {
                require_once $file;
                //实例化类，并调用客户端指定的方法
                $obj = new $class();
                //如果有参数，则传入指定参数
                if (!$params) {
                    $data = $obj->$method();
                } else {
                    $data = $obj->$method($params);
                }
                // 打包数据
                $this->packProtocol($data);
                //把运行后的结果返回给客户端
                fwrite($client, $data);
            }
        } else {
            fwrite($client, 'class or method error');
        }
    }

    /**
     * @DESC          # 解析协议
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:56
     * 参数区：
     *
     * @param $buf
     * @param $class
     * @param $method
     * @param $params
     */
    private function parseProtocol($buf, &$class, &$method, &$params): void
    {
        $buf    = json_decode($buf, true);
        $class  = $buf['class'];
        $method = $buf['method'];
        $params = $buf['params'];
    }

    /**
     * @DESC          # 打包协议
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午2:56
     * 参数区：
     *
     * @param $data
     */
    private function packProtocol(&$data): void
    {
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
