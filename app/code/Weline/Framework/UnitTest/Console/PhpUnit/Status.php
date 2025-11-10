<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09 12:50:00
 */

namespace Weline\Framework\UnitTest\Console\PhpUnit;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;

class Status implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        System   $system,
        Printing $printing
    )
    {
        $this->system = $system;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 提示是否运行：生产环境禁止运行
        if (Env::get('deploy') !== 'dev') {
            $this->printing->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！'));
            exit(1);
        }
        
        $this->checkPhpUnitServerStatus();
    }

    /**
     * 检查PHPUnit报告服务器状态
     */
    private function checkPhpUnitServerStatus(): void
    {
        $pidFile = BP . 'var' . DS . 'phpunit_server.pid';
        $envFile = BP . 'app' . DS . 'etc' . DS . 'env.php';
        
        $this->printing->note(__('PHPUnit报告服务器状态检查'));
        $this->printing->note(__('================================'));
        
        # 检查PID文件
        if (!file_exists($pidFile)) {
            $this->printing->note(__('PID文件不存在: %{1}', $pidFile));
            $this->printing->note(__('状态: 未运行'));
            return;
        }
        
        $pid = (int)file_get_contents($pidFile);
        $this->printing->note(__('PID文件: %{1}', $pidFile));
        $this->printing->note(__('进程ID: %{1}', (string)$pid));
        
        # 从env.php读取服务器信息
        $serverPort = 9980;
        $serverHost = '127.0.0.1';
        if (file_exists($envFile)) {
            $env = include $envFile;
            if (isset($env['phpunit_server'])) {
                $serverPort = $env['phpunit_server']['port'] ?? 9980;
                $serverHost = $env['phpunit_server']['host'] ?? '127.0.0.1';
            }
        }
        
        # 使用Processer的方法检查进程是否在运行（更可靠）
        if (Processer::isRunningByPid($pid)) {
            $this->printing->success(__('状态: 运行中'));
            
            # 显示运行时间
            if (file_exists($envFile)) {
                $env = include $envFile;
                if (isset($env['phpunit_server']['start_time'])) {
                    $startTime = $env['phpunit_server']['start_time'];
                    $runTime = time() - $startTime;
                    $this->printing->note(__('运行时间: %{1}秒', (string)$runTime));
                    $this->printing->note(__('启动时间: %{1}', date('Y-m-d H:i:s', $startTime)));
                }
            }
            
            $this->printing->note(__('访问地址: http://%{1}:%{2}', [$serverHost, (string)$serverPort]));
            
            # 检查端口是否被占用
            if (Processer::isPortInUse($serverPort)) {
                $this->printing->success(__('端口%{1}: 已占用', (string)$serverPort));
            } else {
                $this->printing->error(__('端口%{1}: 未占用', (string)$serverPort));
            }
            
        } else {
            $this->printing->error(__('状态: 未运行 (进程不存在)'));
            $this->printing->note(__('建议: 清理PID文件并重新启动'));
            
            # 如果进程不存在但端口被占用，提示可能被其他进程占用
            if (Processer::isPortInUse($serverPort)) {
                $this->printing->warning(__('注意: 端口%{1}仍被占用，可能被其他进程使用', (string)$serverPort));
            }
        }
        
        # 显示env.php中的信息
        if (file_exists($envFile)) {
            $env = include $envFile;
            if (isset($env['phpunit_server'])) {
                $this->printing->note(__('================================'));
                $this->printing->note(__('env.php中的服务器信息:'));
                $this->printing->note(__('主机: %{1}', $env['phpunit_server']['host'] ?? 'N/A'));
                $this->printing->note(__('端口: %{1}', (string)($env['phpunit_server']['port'] ?? 'N/A')));
                $this->printing->note(__('PID: %{1}', (string)($env['phpunit_server']['pid'] ?? 'N/A')));
                $this->printing->note(__('状态: %{1}', $env['phpunit_server']['status'] ?? 'N/A'));
            }
        }
    }
    

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '检查PHPUnit报告服务器状态';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
