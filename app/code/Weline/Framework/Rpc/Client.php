<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：30/9/2022 15:02:27
 */

namespace Weline\Framework\Rpc;

class Client
{
    /**
     * 调用的地址
     * @var array
     */
    private array $url_info = [];

    /**
     * @param $url
     */
    public function __construct($url)
    {
        $this->url_info = parse_url($url);
    }

    /**
     * @DESC          # 返回当前对象
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午3:06
     * 参数区：
     *
     * @param $url
     *
     * @return Client
     */
    public static function instance($url)
    {
        return new Client($url);
    }

    /**
     * @DESC          # 魔术方法调用
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 30/9/2022 下午3:06
     * 参数区：
     *
     * @param $name
     * @param $arguments
     *
     * @return false|string|void
     */
    public function __call($name, $arguments)
    {
        //创建一个客户端
        $client = stream_socket_client("tcp://{$this->url_info['host']}:{$this->url_info['port']}", $errno, $errstr);
        if (!$client) {
            exit("{$errno} : {$errstr} \n");
        }
        $data = [
            'class'  => basename($this->url_info['path']),
            'method' => $name,
            'params' => $arguments
        ];
        //向服务端发送我们自定义的协议数据
        fwrite($client, json_encode($data));
        //读取服务端传来的数据
        $data = fread($client, 2048);
        //关闭客户端
        fclose($client);
        return $data;
    }
}
