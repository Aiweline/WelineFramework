<?php
declare(strict_types=1);

/**
 * Weline Server - 停止命令
 * 
 * 支持按实例名称停止服务器，树形显示停止的子进程
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Service\CliServerService;

/**
 * server:stop - 停止常驻内存服务器
 */
class Stop extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析参数
        $instanceName = $this->parseInstanceName($args);
        $stopAll = isset($args['all']) || isset($args['a']);
        $force = isset($args['force']) || isset($args['f']);
        
        if ($stopAll) {
            $this->stopAllInstances($force);
            return;
        }
        
        $this->stopInstance($instanceName, $force);
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        
        return $positionalArgs[0] ?? 'default';
    }

    /**
     * 按端口查找占用该端口的 Weline Server 实例名（端口落在实例的 port ~ port+count-1 内）
     */
    public function findWelineServerInstanceNameByPort(int $port): ?string
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            return null;
        }
        $files = \glob($instanceDir . '*.json');
        foreach ($files as $file) {
            $name = \basename($file, '.json');
            $data = \json_decode(\file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }
            $instancePort = (int) ($data['port'] ?? 0);
            $count = (int) ($data['count'] ?? 4);
            if ($instancePort <= $port && $port < $instancePort + $count) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 若指定端口被 Weline Server 占用则停止该实例（同端口只能存在一个服务器，供 CLI 启动前调用）
     *
     * @return bool 是否停止了某个 WLS 实例
     */
    public function stopWelineServerOnPort(int $port): bool
    {
        $name = $this->findWelineServerInstanceNameByPort($port);
        if ($name === null) {
            return false;
        }
        $this->stopInstance($name, true);
        return true;
    }
    
    /**
     * 停止单个实例（含 cli/cli-server 委托给 PHP 内置服务器停止逻辑）
     */
    protected function stopInstance(string $name, bool $force = false): void
    {
        $nameLower = strtolower($name);
        if ($nameLower === 'cli' || $nameLower === 'cli-server') {
            $this->stopCliServer($force);
            return;
        }

        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $name . '.json';
        
        if (!\is_file($instanceFile)) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            $this->printer->note(__('使用 server:listing 查看所有实例'));
            return;
        }
        
        $instanceData = \json_decode(\file_get_contents($instanceFile), true) ?: [];
        $port = $instanceData['port'] ?? Start::DEFAULT_PORT;
        $count = $instanceData['count'] ?? 4;
        $host = $instanceData['host'] ?? '127.0.0.1';
        
        $this->printer->setup(__('停止 Weline Server'));
        echo "\n";
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                   停止服务器实例                               ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $name));
        $this->printer->note(\sprintf('║  端口范围：%-50s║', "{$port} - " . ($port + $count - 1)));
        $this->printer->note(\sprintf('║  进程数量：%-50s║', $count));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // 停止所有 Worker 进程
        $this->printer->note(__('停止 Worker 进程...'));
        echo "\n";
        
        $stoppedCount = 0;
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
            $workerId = $i + 1;
            
            if (Processer::isPortInUse($workerPort)) {
                Processer::killProcessByPort($workerPort);
                $this->printer->success(__('  ├─ Worker #%{1} (端口: %{2}) - 已停止 ✓', [$workerId, $workerPort]));
                $stoppedCount++;
            } else {
                $this->printer->note(__('  ├─ Worker #%{1} (端口: %{2}) - 未运行', [$workerId, $workerPort]));
            }
        }
        
        echo "\n";
        
        // 删除实例文件
        @\unlink($instanceFile);
        
        // 清理日志文件（可选）
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
            $logFile = Env::VAR_DIR . 'log' . DS . "worker-{$workerPort}.log";
            if (\is_file($logFile)) {
                @\unlink($logFile);
            }
        }
        
        $this->printer->success(__('实例 [%{1}] 已停止，共停止 %{2}/%{3} 个进程', [$name, $stoppedCount, $count]));
    }
    
    /**
     * 停止所有实例（含 Weline Server 与 PHP 内置 CLI 服务器）
     */
    protected function stopAllInstances(bool $force = false): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instances = \is_dir($instanceDir) ? \glob($instanceDir . '*.json') : [];
        $cliService = ObjectManager::getInstance(CliServerService::class);
        $cliStatus = $cliService->getCliServerStatus();

        if (empty($instances) && !$cliStatus) {
            $this->printer->warning(__('没有正在运行的实例'));
            return;
        }

        $this->printer->setup(__('停止所有服务器实例'));
        echo "\n";

        if (!empty($instances)) {
            $totalInstances = \count($instances);
            $this->printer->note(__('发现 %{1} 个 Weline Server 实例', [$totalInstances]));
            echo "\n";
            foreach ($instances as $instanceFile) {
                $name = \basename($instanceFile, '.json');
                $this->printer->note(__('正在停止实例 [%{1}]...', [$name]));
                $this->stopInstance($name, $force);
                echo "\n";
            }
            $this->printer->success(__('所有 Weline Server 实例已停止'));
        }

        if ($cliStatus) {
            echo "\n";
            $this->printer->note(__('正在停止 PHP 内置服务器 (cli-server)...'));
            $this->stopCliServer($force);
            $this->printer->success(__('PHP 内置服务器已停止'));
        }
    }

    /**
     * 停止 PHP 内置 CLI 服务器（委托给 Console\Console\Server\Stop）
     */
    protected function stopCliServer(bool $force = false): void
    {
        $args = $force ? ['force' => true, 'f' => true] : [];
        $cliStop = ObjectManager::getInstance(CliStop::class);
        $cliStop->execute($args, []);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止 Weline Server 或 PHP 内置服务器实例');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:stop [name]',
            __('停止正在运行的 Weline Server 或 PHP 内置服务器实例'),
            [
                '[name]' => __('实例名称（默认：default；cli/cli-server 表示 PHP 内置服务器）'),
                '-a, --all' => __('停止所有运行中的实例（含 Weline Server 与 CLI 服务器）'),
                '-f, --force' => __('强制停止'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止默认实例') => 'php bin/w server:stop',
                __('停止指定实例') => 'php bin/w server:stop api-server',
                __('停止 PHP 内置服务器') => 'php bin/w server:stop cli-server',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('强制停止') => 'php bin/w server:stop -f',
            ]
        );
    }
}
