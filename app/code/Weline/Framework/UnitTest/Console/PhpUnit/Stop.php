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

class Stop implements \Weline\Framework\Console\CommandInterface
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
        if (Env::system('deploy') !== 'dev') {
            $this->printing->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！'));
            exit(1);
        }
        
        $this->stopPhpUnitServer();
    }

    /**
     * 停止PHPUnit报告服务器
     */
    private function stopPhpUnitServer(): void
    {
        $pidFile = BP . 'var' . DS . 'phpunit_server.pid';
        
        if (!file_exists($pidFile)) {
            $this->printing->note(__('PHPUnit报告服务器未运行'));
            return;
        }
        
        $pid = (int)file_get_contents($pidFile);
        
        if (!$this->isProcessRunning($pid)) {
            $this->printing->note(__('PHPUnit报告服务器未运行 (PID: %{1})', $pid));
            unlink($pidFile);
            return;
        }
        
        # 停止进程
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /PID $pid /F");
        } else {
            exec("kill $pid");
        }
        
        # 清理PID文件
        unlink($pidFile);
        
        # 更新env.php中的服务器信息
        $this->updateServerInfo(0, 'stopped');
        
        $this->printing->success(__('PHPUnit报告服务器已停止 (PID: %{1})', $pid));
    }
    
    /**
     * 检查进程是否在运行
     * 
     * @param int $pid 进程ID
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec("tasklist /FI \"PID eq $pid\"", $output);
            return count($output) > 1;
        } else {
            return posix_kill($pid, 0);
        }
    }
    
    /**
     * 更新env.php中的服务器信息
     * 
     * @param int $pid 进程ID
     * @param string $status 状态
     */
    private function updateServerInfo(int $pid, string $status = 'running'): void
    {
        $envFile = BP . 'app' . DS . 'etc' . DS . 'env.php';
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $env = include $envFile;
        
        if (!isset($env['dev'])) {
            $env['dev'] = [];
        }
        
        $env['dev']['phpunit_server'] = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'pid' => $pid,
            'start_time' => time(),
            'status' => $status,
        ];
        
        # 写入env.php文件
        $content = "<?php return " . var_export($env, true) . ";";
        file_put_contents($envFile, $content);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '停止PHPUnit报告服务器';
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
