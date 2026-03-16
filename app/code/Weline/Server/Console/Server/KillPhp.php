<?php
declare(strict_types=1);

/**
 * Weline Server - 一键结束系统中所有 PHP 进程
 *
 * Windows: taskkill /F /IM php.exe /T
 * Linux/Mac: pkill -9 php
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * server:kill-php - 结束系统中所有 php 进程
 */
class KillPhp extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $isWin = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';

        $this->printer->warning(__('即将结束系统中所有 PHP 进程（含 WLS、CLI 服务器等）'));
        $this->printer->note(__('正在执行…'));

        if ($isWin) {
            $output = [];
            $exitCode = 0;
            @\exec('taskkill /F /IM php.exe /T 2>NUL', $output, $exitCode);
            if ($exitCode === 0) {
                $this->printer->success(__('已结束所有 php.exe 进程'));
                if (!empty($output)) {
                    foreach ($output as $line) {
                        $this->printer->note('  ' . $line);
                    }
                }
            } else {
                $this->printer->note(__('未检测到运行中的 php.exe，或已全部结束'));
            }
        } else {
            @\exec('pkill -9 php 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                $this->printer->success(__('已结束所有 php 进程'));
            } else {
                $this->printer->note(__('未检测到运行中的 php 进程，或已全部结束'));
            }
        }
    }

    public function tip(): string
    {
        return __('一键结束系统中所有 PHP 进程（Windows: taskkill /F /IM php.exe /T；Linux/Mac: pkill -9 php）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:kill-php',
            __('一键结束系统中所有 PHP 进程'),
            [],
            [],
            [
                __('执行后端口会释放，可再运行 server:start') => 'php bin/w server:kill-php',
            ]
        );
    }
}
