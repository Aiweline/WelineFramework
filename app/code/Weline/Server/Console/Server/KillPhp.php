<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:kill-php - safe PHP cleanup for WLS-owned processes.
 */
class KillPhp extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $allPhp = isset($args['all-php']) || isset($args['all_php']);
        $confirmed = isset($args['yes']) || isset($args['y']) || isset($args['force']);

        if ($allPhp) {
            if (!$confirmed) {
                $this->printer->error(__('拒绝执行全局 PHP 进程杀伤。'));
                $this->printer->note(__('如确需杀死系统中所有 PHP 进程，请显式使用 --all-php --yes。'));
                return;
            }
            $this->killAllPhpProcesses();
            return;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $instances = $manager->getAllPersistedInstanceInfo();
        if ($instances === []) {
            $this->printer->note(__('未发现 WLS 实例记录；不会执行全局 php.exe/pkill。'));
            return;
        }

        $prefixes = [];
        foreach (\array_keys($instances) as $name) {
            $prefixes[] = MasterProcess::getMasterProcessName((string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-worker', (string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-dispatcher', (string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-session', (string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-memory', (string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-redirect', (string)$name);
            $prefixes[] = MasterProcess::buildScopedProcessName('weline-wls-maintenance', (string)$name);
        }
        $prefixes = \array_values(\array_unique(\array_filter($prefixes)));
        if ($prefixes === []) {
            $this->printer->note(__('未找到可清理的 WLS 进程前缀。'));
            return;
        }

        $this->printer->warning(__('将仅结束当前项目 WLS 归属的 PHP 进程，不会杀死系统中所有 PHP。'));
        Processer::killByProcessNamePrefixes($prefixes);
        $this->printer->success(__('已发送 WLS 归属 PHP 进程清理请求。'));
        $this->printer->note(__('如需查看残留，请执行 php bin/w server:status --all。'));
    }

    private function killAllPhpProcesses(): void
    {
        $this->printer->warning(__('即将结束系统中所有 PHP 进程（包含非 WLS 进程）。'));
        $isWin = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        $output = [];
        $exitCode = 0;
        if ($isWin) {
            @\exec('taskkill /F /IM php.exe /T 2>NUL', $output, $exitCode);
        } else {
            @\exec('pkill -9 php 2>/dev/null', $output, $exitCode);
        }

        if ($exitCode === 0) {
            $this->printer->success(__('已执行全局 PHP 进程清理。'));
            foreach ($output as $line) {
                $this->printer->note('  ' . $line);
            }
            return;
        }

        $this->printer->note(__('未检测到运行中的 PHP 进程，或系统拒绝执行。'));
    }

    public function tip(): string
    {
        return __('结束 WLS 归属 PHP 进程；全局 PHP 清理必须显式 --all-php --yes');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:kill-php',
            __('默认只结束当前项目 WLS 归属 PHP 进程'),
            [
                '--all-php' => __('危险：杀死系统中所有 PHP 进程'),
                '--yes' => __('与 --all-php 同用，确认全局杀伤'),
            ],
            [
                __('安全默认') => __('不再执行 taskkill /IM php.exe 或 pkill -9 php，除非显式 --all-php --yes'),
            ],
            [
                __('清理 WLS 归属进程') => 'php bin/w server:kill-php',
                __('全局清理（危险）') => 'php bin/w server:kill-php --all-php --yes',
            ]
        );
    }
}
