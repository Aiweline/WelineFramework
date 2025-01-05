<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Console\Console\Deploy\Mode\Set;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Start implements CommandInterface
{
    function __construct(
        private Set      $set,
        private System   $system,
        private Printing $printer
    )
    {

    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $host = $args['host'] ?? $args['h'] ?? '127.0.0.1';
        $port = $args['port'] ?? $args['p'] ?? '9981';
        # 咨询，WEB服务器会将部署模式设置为DEV
        $this->printer->note(__('启用PHP内资本地WebServer服务...'));
        $this->printer->note(__('后端地址：http://%1:%2/%3/admin/login', [$host, $port, Env::get('admin')]));
        $this->printer->note(__('后端API地址：http://%1:%2/%3/rest', [$host, $port, Env::get('api_admin')]));
        # 局域网
        # 获取本机局域网IP
        $this->printer->note(__('局域网访问：'));
        $this->printer->note(__('局域网地址：http://%1:%2/%3/admin/login', [$this->system->getLocalIp(), $port, Env::get('admin')]));
        $this->printer->note(__('局域网API地址：http://%1:%2/%3/rest', [$this->system->getLocalIp(), $port, Env::get('api_admin')]));

        # 调用静态文件部署
        if (Env::get('deploy') !== 'dev') {
            $this->printer->setup(__('启用PHP内置服务器需要将部署模式\'设置为dev，当前部署模式为 %1，是否继续(y/n)?', Env::get('deploy') ?? 'default'));
            $input = $this->system->input();
            if (strtolower(chop($input)) !== 'y' && strtolower(chop($input)) !== 'yes') {
                $this->printer->setup('已为您取消操作！');
                return;
            }
            $this->set->deploy('dev');
        }
        Server::instance($host, $port);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '启用PHP内资本地WebServer服务。';
    }
}