<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Console\Console\Deploy\Mode\Set;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;

class Start implements CommandInterface
{
    use TablePrinter;
    
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
        
        # 检查是否是前台运行（默认后台运行，除非明确指定前台）
        # 如果用户指定了 -f 或 --foreground，则前台运行；否则默认后台运行
        $isForeground = isset($args['f']) || isset($args['foreground']);
        $backend = !$isForeground; // 默认后台运行
        
        # 强制重启参数改为 --force 或 -r
        $force = $args['force'] ?? $args['r'] ?? false;
        
        # 显示运行模式
        if ($backend) {
            $this->printer->note(__('运行模式: 后台运行 (默认)'));
        } else {
            $this->printer->note(__('运行模式: 前台运行'));
        }
        
        // 检查服务是否已经运行
        $runningInfo = $this->isServerRunning($host, $port);
        if ($runningInfo['running']) {
            if ($force) {
                // 强制启动模式：先停止现有服务器
                $this->printer->note(__('检测到服务器已在运行中，强制启动模式将先停止现有服务器...'));
                
                if ($runningInfo['pid']) {
                    $this->printer->note(__('正在停止现有服务器进程，进程ID：%{1}', [$runningInfo['pid']]));
                    if ($this->stopExistingServer($runningInfo['pid'])) {
                        $this->printer->success(__('现有服务器已成功停止'));
                    } else {
                        $this->printer->error(__('停止现有服务器失败，请手动停止后重试'));
                        return;
                    }
                } else {
                    // 通过端口停止
                    $actualPid = $this->getProcessIdByPort($port);
                    if ($actualPid) {
                        $this->printer->note(__('通过端口检测到进程，正在停止，进程ID：%{1}', [$actualPid]));
                        if ($this->stopExistingServer($actualPid)) {
                            $this->printer->success(__('现有服务器已成功停止'));
                        } else {
                            $this->printer->error(__('停止现有服务器失败，请手动停止后重试'));
                            return;
                        }
                    } else {
                        $this->printer->warning(__('端口被占用但无法确定进程ID，尝试强制清理端口'));
                        // 等待一下让端口释放
                        sleep(3);
                    }
                }
                
                // 清理配置
                $this->clearServerConfig();
                
                // 等待端口完全释放
                $this->printer->note(__('等待端口释放...'));
                sleep(2);
                
                // 触发服务器停止事件（强制重启时）
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                $stopEventData = [
                    'pid' => $runningInfo['pid'],
                    'host' => $host,
                    'port' => $port,
                    'force' => true,
                    'reason' => 'force_restart'
                ];
                $eventManager->dispatch('Weline_Framework_Server::stop_after', $stopEventData);
                
            } else {
                // 非强制模式：显示现有服务器信息
                if ($runningInfo['pid']) {
                    $this->printer->success(__('检测到服务器已在运行中！进程ID：%{1}', [$runningInfo['pid']]));
                    
                    // 检查配置是否完整，如果不完整则更新
                    $env = Env::getInstance();
                    $serverConfig = $env->get('server') ?? [];
                    if (empty($serverConfig) || !isset($serverConfig['pid']) || $serverConfig['pid'] != $runningInfo['pid']) {
                        $this->saveServerPid($host, $port, $runningInfo['pid']);
                        $this->printer->note(__('已自动更新配置信息。'));
                    }
                    
                    // 显示服务器信息
                    echo "\n";
                    $this->printTable('服务访问地址', [
                        ['前端首页', "http://{$host}:{$port}/"],
                        ['前端API', "http://{$host}:{$port}/api/rest"],
                        ['后端管理', "http://{$host}:{$port}/" . Env::get('admin') . "/admin/login"],
                        ['后端API', "http://{$host}:{$port}/" . Env::get('api_admin') . "/rest"],
                    ], true, 0, false); // false 表示不截断URL，完整显示地址
                    echo "\n";
                    
                    // 如果是后台模式，显示后台运行信息
                    $this->printer->success(__('服务器已在后台运行'));
                    $this->printer->warning(__('如果需要停止服务器，请使用 "php bin/w server:stop" 命令'));
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -r" 命令'));
                    
                    return;
                } else {
                    $this->printer->warning(__('检测到端口被占用，但无法获取进程信息'));
                    echo "\n";
                    $this->printTable('服务访问地址', [
                        ['前端首页', "http://{$host}:{$port}/"],
                        ['前端API', "http://{$host}:{$port}/api/rest"],
                        ['后端管理', "http://{$host}:{$port}/" . Env::get('admin') . "/admin/login"],
                        ['后端API', "http://{$host}:{$port}/" . Env::get('api_admin') . "/rest"],
                    ], true, 0, false); // false 表示不截断URL，完整显示地址
                    echo "\n";
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -r" 命令'));
                    return;
                }
            }
        }
        
        # 咨询，WEB服务器会将部署模式设置为DEV
        $this->printer->warning(__('开发专用，请勿用于生产环境。'));
        $this->printer->note(__('启用PHP内置本地WebServer服务...'));
        echo "\n";
        
        // 本地访问地址
        $this->printTable('本地访问地址', [
            ['前端首页', "http://{$host}:{$port}/"],
            ['前端API', "http://{$host}:{$port}/api/rest"],
            ['后端管理', "http://{$host}:{$port}/" . Env::get('admin') . "/admin/login"],
            ['后端API', "http://{$host}:{$port}/" . Env::get('api_admin') . "/rest"],
        ], true, 0, false); // false 表示不截断URL，完整显示地址
        
        echo "\n";
        
        // 局域网访问地址
        $localIp = $this->system->getLocalIp();
        $this->printTable('局域网访问地址', [
            ['前端首页', "http://{$localIp}:{$port}/"],
            ['前端API', "http://{$localIp}:{$port}/api/rest"],
            ['后端管理', "http://{$localIp}:{$port}/" . Env::get('admin') . "/admin/login"],
            ['后端API', "http://{$localIp}:{$port}/" . Env::get('api_admin') . "/rest"],
        ], true, 0, false); // false 表示不截断URL，完整显示地址
        
        echo "\n";

        # 检查部署模式，如果是生产环境给出强烈警告
        $currentMode = Env::get('deploy');
        if ($currentMode === 'prod' || $currentMode === 'production') {
            $this->printer->error(__('⚠️  警告：当前处于生产环境模式（%{1}）！', [$currentMode]));
            $this->printer->error(__('⚠️  PHP内置服务器仅供开发和测试使用，不适合生产环境！'));
            $this->printer->error(__('⚠️  生产环境请使用Nginx或Apache等专业Web服务器！'));
            $this->printer->error(__('⚠️  继续使用PHP内置服务器可能导致性能问题和安全风险！'));
            
            // 使用echo直接输出提示，不换行，然后等待输入
            echo $this->printer->colorize(__('是否仍要在生产模式下启动PHP内置服务器？(输入 y/yes 继续，其他任意键取消):'), 'Red');
            $input = $this->system->input();
            
            $inputLower = strtolower(trim($input));
            if ($inputLower !== 'yes' && $inputLower !== 'y') {
                $this->printer->setup('已为您取消操作！建议使用 php bin/w deploy:mode:set dev 切换到开发模式。');
                return;
            }
            $this->printer->error(__('⚠️  您已选择在生产模式下使用PHP内置服务器，请自行承担风险！'));
        }
        
        // 启动服务器并记录进程ID
        $pid = Server::instance($host, $port, $backend);
        
        // 调试信息
        if ($backend) {
            if (function_exists('proc_open')) {
                $this->printer->note(__('使用proc_open启动后台进程'));
            } else {
                $this->printer->note(__('proc_open不可用，使用传统方式启动后台进程'));
            }
        }
        
        // 如果后台模式下PID为null，尝试通过端口获取PID
        if ($backend && !$pid) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($connection !== false) {
                fclose($connection);
                // 尝试获取占用端口的进程ID
                $pid = $this->getProcessIdByPort($port);
                if ($pid) {
                    $this->printer->note(__('通过端口检测到服务器进程，进程ID：%{1}', [$pid]));
                }
            }
        }
        
        // 如果后台模式下仍然没有PID，但端口被占用，说明启动成功
        if ($backend && !$pid) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($connection !== false) {
                fclose($connection);
                $this->printer->success(__('服务器已在后台启动成功！'));
                $this->printer->note(__('使用 "php bin/w server:stop" 停止服务器'));
                $this->printer->success(__('程序已进入后台运行'));
                return;
            }
        }
        
        if ($pid) {
            $this->saveServerPid($host, $port, $pid);
            
            // 触发服务器启动事件
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'pid' => $pid,
                'host' => $host,
                'port' => $port,
                'backend' => $backend,
                'start_time' => time(),
                'force' => $force
            ];
            $eventManager->dispatch('Weline_Framework_Server::start_after', $eventData);
            
            if ($backend) {
                $this->printer->success(__('服务器已在后台启动成功！进程ID：%{1}', [$pid]));
                $this->printer->note(__('使用 "php bin/w s:stop" 停止服务器'));
                $this->printer->success(__('程序已进入后台运行，可以关闭终端'));
                
                // 后台模式下，打印完信息后自动退出
                return;
            } else {
                $this->printer->success(__('服务器启动成功！进程ID：%{1}', [$pid]));
                $this->printer->note(__('按 Ctrl+C 停止服务器'));
            }
        } else if ($backend) {
            // 后台模式下，如果PID为null，说明启动失败
            $this->printer->error(__('后台启动失败，请检查端口是否被占用'));
        }
    }

    /**
     * 检查服务器是否正在运行
     * @return array ['running' => bool, 'pid' => int|null]
     */
    private function isServerRunning(string $host, int $port): array
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        if (isset($serverConfig['pid']) && $serverConfig['pid']) {
            $isRunning = Processer::isRunningByPid((int)$serverConfig['pid']);
            return [
                'running' => $isRunning,
                'pid' => $isRunning ? (int)$serverConfig['pid'] : null
            ];
        }
        
        // 检查端口是否被占用
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        
        if ($connection !== false) {
            fclose($connection);
            
            // 尝试获取占用端口的进程ID
            $pid = $this->getProcessIdByPort($port);
            
            return [
                'running' => true,
                'pid' => $pid
            ];
        }
        
        return [
            'running' => false,
            'pid' => null
        ];
    }

    /**
     * 通过端口获取进程ID
     */
    private function getProcessIdByPort(int $port): ?int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            @exec('netstat -ano | findstr ":' . $port . '"', $output);
            
            if (!empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                        $pid = (int)$matches[1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            // Unix/Linux系统
            $output = [];
            @exec("lsof -ti:{$port} 2>/dev/null", $output);
            
            if (!empty($output)) {
                $pid = (int)trim($output[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        return null;
    }

    /**
     * 停止现有服务器
     */
    private function stopExistingServer(int $pid): bool
    {
        Processer::killByPid($pid);
        usleep(500000);
        return !Processer::isRunningByPid($pid);
    }

    /**
     * 检查进程是否正在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }

    /**
     * 清理服务器配置
     */
    private function clearServerConfig(): void
    {
        $env = Env::getInstance();
        $config = $env->getConfig();
        
        if (isset($config['server'])) {
            unset($config['server']);
            // 重新设置整个配置，不传递null值
            $env->setConfig('server', []);
            $env->save();
        }
    }

    /**
     * 保存服务器进程ID到环境配置
     */
    private function saveServerPid(string $host, int $port, int $pid): void
    {
        $env = Env::getInstance();
        
        $serverConfig = [
            'host' => $host,
            'port' => $port,
            'pid' => $pid,
            'start_time' => time(),
            'status' => 'running'
        ];
        
        $env->setConfig('server', $serverConfig);
        $env->save();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '启用PHP内置本地WebServer服务。开发专用，请勿用于生产环境。默认后台运行，使用 -f 或 --foreground 参数前台运行，使用 -r 或 --force 参数强制重启。';
    }

    public function help(): array|string
    {
        return '
════════════════════════════════════════════════════════════════════════════════
命令名称: server:start
════════════════════════════════════════════════════════════════════════════════

📖 描述：
    启用PHP内置本地WebServer服务
    开发专用，请勿用于生产环境
    默认后台运行，可指定前台运行模式
    
    ⚡ 重要变更：
    现在默认后台运行！
    如需前台运行，请使用 -f 或 --foreground 参数

🎯 基本语法：
    php bin/w server:start [选项]

🔧 常用选项：
    -f, --foreground        前台运行（实时查看日志输出）
    -r, --force             强制重启（停止现有服务器后重新启动）
    -h, --host=<主机>       指定主机地址（默认：127.0.0.1）
    -p, --port=<端口>       指定端口（默认：9981）
    --help                  显示此帮助信息

📋 使用方式：

1️⃣ 默认启动（后台运行）：
    php bin/w server:start                # 默认后台运行
    php bin/w server:start -p 8080        # 指定端口后台运行

2️⃣ 前台运行（查看实时日志）：
    php bin/w server:start -f             # 前台运行，实时查看日志
    php bin/w server:start --foreground   # 前台运行（完整参数名）

3️⃣ 强制重启：
    php bin/w server:start -r             # 强制重启服务器
    php bin/w server:start --force        # 强制重启（完整参数名）

4️⃣ 自定义配置：
    php bin/w server:start -h 0.0.0.0 -p 8080    # 监听所有网卡，端口8080

💡 提示：
    - 后台模式：适合日常开发，不阻塞终端
    - 前台模式：适合调试，可以实时查看请求日志
    - 使用 "php bin/w server:stop" 停止后台运行的服务器
    - 使用 "php bin/w server:status" 查看服务器状态

════════════════════════════════════════════════════════════════════════════════
';
    }
}