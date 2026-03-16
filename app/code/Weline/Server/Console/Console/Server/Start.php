<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
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
        $port = (int) ($args['port'] ?? $args['p'] ?? 9981);
        
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
        
        // 同端口只能存在一个服务器：若该端口被 Weline Server 占用，先彻底停止 WLS 实例下所有进程再启动 CLI
        $mainStop = ObjectManager::getInstance(\Weline\Server\Console\Server\Stop::class);
        if ($mainStop->stopWelineServerOnPort($port)) {
            $this->printer->note(__('已停止占用端口 %{1} 的 Weline Server，以便启动 CLI 服务器', [$port]));
            $this->printer->note(__('等待端口释放...'));
            $this->waitForPortReleased($host, $port, 12);
        } else {
            // 实例未找到时仍可能被 WLS 进程占用（如实例文件缺失），按端口强杀 WLS 进程
            if ($mainStop->killWlsProcessOnPort($port)) {
                $this->printer->note(__('等待端口释放...'));
                $this->waitForPortReleased($host, $port, 12);
            }
        }
        
        // 检查服务是否已经运行
        $runningInfo = $this->isServerRunning($host, $port);
        if ($runningInfo['running']) {
            if ($force) {
                // 强制启动模式：先停止现有服务器
                $this->printer->note(__('检测到服务器已在运行中，强制启动模式将先停止现有服务器...'));
                
                // 优先用 Processer 停止（与 WLS 一致：后台启动的 CLI 会登记为 weline-cli-server-{port}）
                if ($this->tryStopCliServerByProcesser($port)) {
                    $this->printer->success(__('现有服务器已成功停止'));
                } else {
                    $stoppedPid = $runningInfo['pid'] ?: $this->getProcessIdByPort($port);
                    if ($stoppedPid) {
                        if (!Processer::isRunningByPid($stoppedPid)) {
                            $this->printer->note(__('端口被占用，但检测到的占用进程已不存在，跳过停止。'));
                        } else {
                            if (!$runningInfo['pid']) {
                                $this->printer->note(__('通过端口检测到进程，正在停止，进程ID：%{1}', [$stoppedPid]));
                            } else {
                                $this->printer->note(__('正在停止现有服务器进程，进程ID：%{1}', [$stoppedPid]));
                            }
                            if (!$this->stopExistingServer($stoppedPid, $port, $host)) {
                                if (!Processer::isRunningByPid($stoppedPid)) {
                                    $this->printer->note(__('占用进程已退出，视为端口已释放，继续启动。'));
                                } else {
                                    $this->printer->error(__('停止现有服务器失败，请手动停止后重试'));
                                    $this->printManualKillHint($stoppedPid);
                                    return;
                                }
                            } else {
                                $this->printer->success(__('现有服务器已成功停止'));
                            }
                        }
                    } else {
                        $this->printer->warning(__('端口被占用但无法确定进程ID，尝试强制清理端口'));
                        SchedulerSystem::sleep(3);
                    }
                }
                
                // 清理配置
                $this->clearServerConfig();
                
                // 等待端口完全释放
                $this->printer->note(__('等待端口释放...'));
                SchedulerSystem::sleep(2);
                
                // 触发服务器停止事件（强制重启时）
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                $stopEventData = [
                    'pid' => $runningInfo['pid'],
                    'host' => $host,
                    'port' => $port,
                    'force' => true,
                    'reason' => 'force_restart'
                ];
                $eventManager->dispatch('Weline_Server::stop_after', $stopEventData);
                
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
                    
                    // 显示服务器信息（统一表，左右无边界）
                    echo "\n";
                    $this->printTable(__('服务访问地址'), $this->buildServerAddressRows($host, $port), true, 0, false, true);
                    echo "\n";
                    
                    // 如果是后台模式，显示后台运行信息
                    $this->printer->success(__('服务器已在后台运行'));
                    $this->printer->warning(__('如果需要停止服务器，请使用 "php bin/w server:stop" 命令'));
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -r" 命令'));
                    
                    return;
                } else {
                    $this->printer->warning(__('检测到端口被占用，但无法获取进程信息'));
                    echo "\n";
                    $this->printTable(__('服务访问地址'), $this->buildServerAddressRows($host, $port), true, 0, false, true);
                    echo "\n";
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -r" 命令'));
                    return;
                }
            }
        }
        
        // 启动前先确保端口已释放（再打印地址表并启动，避免“先展示再报占用”的错序）
        if (!$this->ensurePortFreeBeforeStart($host, $port)) {
            return;
        }
        
        # 咨询，WEB服务器会将部署模式设置为DEV
        $this->printer->warning(__('开发专用，请勿用于生产环境。'));
        $this->printer->note(__('启用PHP内置本地WebServer服务...'));
        echo "\n";
        
        // 本地、局域网（及公网）统一一张表，左右无边界
        $this->printTable(
            __('服务访问地址'),
            $this->buildServerAddressRows($host, $port),
            true,
            0,
            false,
            true
        );
        echo "\n";

        # 检查部署模式，如果是生产环境给出强烈警告
        $currentMode = Env::system('deploy');
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
        
        // 如果后台模式下PID为null，尝试通过端口获取 PID（仅采用仍存活的进程，避免写入陈旧 PID）
        if ($backend && !$pid) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($connection !== false) {
                fclose($connection);
                $foundPid = $this->getProcessIdByPort($port);
                if ($foundPid && Processer::isRunningByPid($foundPid)) {
                    $pid = $foundPid;
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
            if (Processer::isRunningByPid($pid)) {
                $this->saveServerPid($host, $port, $pid);
            }
            
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
            $eventManager->dispatch('Weline_Server::start_after', $eventData);
            
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
        } else {
            // 前台模式（Windows 为 exec 阻塞，返回时进程已结束；或绑定失败立即退出）
            $this->printer->note(__('服务器进程已结束。若未能正常启动请检查端口是否被占用。'));
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
        
        // 检查端口是否被占用（仅以端口是否可连接为准；占用进程以“当前存活”为准，避免使用系统返回的陈旧 PID）
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        
        if ($connection !== false) {
            fclose($connection);
            $pid = $this->getProcessIdByPort($port);
            if ($pid !== null && !Processer::isRunningByPid($pid)) {
                $pid = null; // 系统返回的 PID 已失效（如 Windows netstat 滞后），不当作当前占用者
            }
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
     * 通过端口向系统查询当前占用该端口的进程 ID（netstat/Get-NetTCPConnection 等）。
     * 注意：进程退出后系统可能短暂仍返回该 PID（尤其 Windows），故调用方需用 isRunningByPid 校验，
     * 未存活则视为“无法确定占用者”，勿当作当前进程重复提示同一 PID。
     */
    private function getProcessIdByPort(int $port): ?int
    {
        $pid = Processer::getProcessIdByPort($port);
        return $pid > 0 ? $pid : null;
    }

    /**
     * 构建服务访问地址表数据（本地 + 局域网统一，左右无边界表格用）
     *
     * @return array<int, array{string, string}> [ [ "类型 · 名称", "URL" ], ... ]
     */
    private function buildServerAddressRows(string $host, int $port): array
    {
        $backendPrefix = Env::getAreaRoutePrefix('backend');
        $restPrefix = Env::getAreaRoutePrefix('rest_backend');
        $localLabel = __('本地');
        $lanLabel = __('局域网');
        $home = __('前端首页');
        $api = __('前端API');
        $admin = __('后端管理');
        $rest = __('后端API');

        $rows = [];
        $rows[] = ["{$localLabel} · {$home}", "http://{$host}:{$port}/"];
        $rows[] = ["{$localLabel} · {$api}", "http://{$host}:{$port}/api/rest"];
        $rows[] = ["{$localLabel} · {$admin}", "http://{$host}:{$port}/{$backendPrefix}/admin/login"];
        $rows[] = ["{$localLabel} · {$rest}", "http://{$host}:{$port}/{$restPrefix}/rest"];

        $localIp = $this->system->getLocalIp();
        if ($localIp !== '' && $localIp !== $host) {
            $rows[] = ["{$lanLabel} · {$home}", "http://{$localIp}:{$port}/"];
            $rows[] = ["{$lanLabel} · {$api}", "http://{$localIp}:{$port}/api/rest"];
            $rows[] = ["{$lanLabel} · {$admin}", "http://{$localIp}:{$port}/{$backendPrefix}/admin/login"];
            $rows[] = ["{$lanLabel} · {$rest}", "http://{$localIp}:{$port}/{$restPrefix}/rest"];
        }
        return $rows;
    }

    /**
     * 轮询等待端口释放（WLS 停止后给系统时间释放端口）
     *
     * @param string $host    监听地址
     * @param int    $port    端口
     * @param int    $timeout 最大等待秒数
     */
    private function waitForPortReleased(string $host, int $port, int $timeout = 12): void
    {
        for ($i = 0; $i < $timeout; $i++) {
            $conn = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($conn === false || !is_resource($conn)) {
                return;
            }
            fclose($conn);
            SchedulerSystem::sleep(1);
        }
    }

    /**
     * 启动前确保端口已释放（按端口强杀占用进程，避免 Weline Server 占端口但实例文件未找到时 CLI 无法绑定）
     *
     * @return bool 端口已释放或未被占用为 true，无法释放或放弃时为 false（调用方应中止启动）
     */
    private function ensurePortFreeBeforeStart(string $host, int $port): bool
    {
        $maxTries = 3;
        $deadPidCount = 0; // 连续出现“端口可连但 PID 已不存在”时，视为进程检测异常，避免死循环
        for ($i = 0; $i < $maxTries; $i++) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($connection === false || !is_resource($connection)) {
                return true;
            }
            fclose($connection);
            $pid = $this->getProcessIdByPort($port);
            if ($pid) {
                // 避免“僵尸 PID”：进程已退出但 netstat/驱动仍返回该 PID（Windows 常见）
                if (!Processer::isRunningByPid($pid)) {
                    $deadPidCount++;
                    $this->printer->note(__('端口 %{1} 仍可连接，但无法确定占用进程（系统返回的 PID 已失效），可能正在释放中…', [$port]));
                    SchedulerSystem::sleep(2);
                    // 等待后再次检测：若端口已不可达则视为已释放，避免误判导致后续绑定失败
                    $retryConn = @fsockopen($host, $port, $errno, $errstr, 1);
                    if ($retryConn === false || !is_resource($retryConn)) {
                        return true;
                    }
                    if (is_resource($retryConn)) {
                        fclose($retryConn);
                    }
                    if ($deadPidCount >= 2) {
                        $this->printer->warning(__('进程检测异常（端口与 PID 不一致），尝试继续启动；若绑定失败请先执行 server:kill-php 或 taskkill 后重试。'));
                        return true;
                    }
                    continue;
                }
                $deadPidCount = 0;
                $this->printer->note(__('端口 %{1} 仍被占用（PID：%{2}），正在停止以便启动 CLI 服务器...', [$port, $pid]));
                // 若为 Processer 登记的 CLI 进程，优先用 destroy（与 WLS 一致）
                if (!$this->tryStopCliServerByProcesser($port)) {
                    $stopped = $this->stopExistingServer($pid, $port, $host);
                } else {
                    $stopped = true;
                }
                if (!$stopped) {
                    $this->printer->warning(__('进程 %{1} 未能成功结束。请手动执行后重试：', [$pid]));
                    $this->printManualKillHint($pid);
                    return false;
                }
                // 等待端口释放（Windows 上有时需更长时间）
                SchedulerSystem::sleep($i < $maxTries - 1 ? 3 : 2);
            } else {
                $this->printer->warning(__('端口 %{1} 被占用但无法获取进程ID，请手动停止后重试', [$port]));
                return false;
            }
        }
        $this->printer->warning(__('端口 %{1} 多次尝试后仍被占用，CLI 可能无法正常监听', [$port]));
        return false;
    }

    /**
     * 若占用端口的进程是 Processer 登记的 CLI 进程（命令行含 weline-cli-server），用 destroy 停止并清理索引（与 WLS 一致）。
     *
     * @return bool 端口已释放为 true，否则 false（未登记或 destroy 后端口仍占用）
     */
    private function tryStopCliServerByProcesser(int $port): bool
    {
        $pid = $this->getProcessIdByPort($port);
        if ($pid === null || $pid <= 0) {
            return false;
        }
        if (!Processer::isRunningByPid($pid)) {
            return false; // 系统返回的 PID 已失效，不当作 CLI 进程处理
        }
        $pname = Processer::getNameByPid($pid);
        if ($pname === '' || $pname === 'unknown' || \strpos($pname, 'weline-cli-server') === false) {
            return false;
        }
        Processer::destroy($pname);
        SchedulerSystem::sleep(1);
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($conn !== false && is_resource($conn)) {
            fclose($conn);
            return false;
        }
        return true;
    }

    /**
     * 停止现有服务器
     *
     * 为可靠释放端口：先尝试杀进程树，再补杀单进程。传入 $port 时以「端口已释放」为准，
     * 不依赖 isRunningByPid（Windows 上 tasklist 可能漏报或延迟），避免误判为已停止。
     *
     * @param int      $pid  要结束的进程 ID
     * @param int|null $port 若提供，则以端口不再被该 PID 占用为准
     * @param string   $host 与 port 同时提供时，occupantPid 为 null 时用 fsockopen(host,port) 二次确认
     */
    private function stopExistingServer(int $pid, ?int $port = null, string $host = '127.0.0.1'): bool
    {
        $driver = Processer::getDriver();
        $cmdLine = Processer::getProcessCommandLine($pid);
        $isPhpServer = $cmdLine !== '' && (
            \stripos($cmdLine, 'php') !== false &&
            \stripos($cmdLine, '-S') !== false
        );
        $isWin = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        $waitAfterKillUs = $isWin ? 1_200_000 : 500000;
        $maxAttempts = $isWin ? 4 : 3;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($isPhpServer) {
                $driver->killProcessTree($pid);
                SchedulerSystem::usleep($waitAfterKillUs);
                if (Processer::isRunningByPid($pid)) {
                    $driver->killProcess($pid);
                    SchedulerSystem::usleep($isWin ? 800000 : 300000);
                }
            } else {
                Processer::killByPid($pid, true);
                SchedulerSystem::usleep($waitAfterKillUs);
                if (Processer::isRunningByPid($pid)) {
                    $driver->killProcessTree($pid);
                    SchedulerSystem::usleep($isWin ? 800000 : 300000);
                }
            }
            // 以端口为准：若传了 port，只有端口不再被该 PID 占用才算成功（Windows 上 isRunningByPid 可能不可靠）
            if ($port !== null) {
                SchedulerSystem::usleep($isWin ? 500000 : 200000);
                $occupantPid = $this->getProcessIdByPort($port);
                if ($occupantPid !== null && $occupantPid !== $pid) {
                    return true;
                }
                // occupantPid === null 时可能是 netstat 漏报，用 fsockopen 再确认端口是否真的释放
                if ($occupantPid === null) {
                    $conn = @fsockopen($host, $port, $errno, $errstr, 1);
                    if ($conn === false || !is_resource($conn)) {
                        return true;
                    }
                    fclose($conn);
                }
            } else {
                if (!Processer::isRunningByPid($pid)) {
                    return true;
                }
            }
        }

        if ($port !== null) {
            return $this->getProcessIdByPort($port) !== $pid;
        }
        return !Processer::isRunningByPid($pid);
    }

    /**
     * 输出手动结束进程的提示（Windows: taskkill，其他: kill -9）
     */
    private function printManualKillHint(int $pid): void
    {
        if (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->printer->note('  taskkill /F /T /PID ' . $pid);
            $this->printer->note(__('若仍无法结束，请以管理员身份运行 CMD 或 PowerShell 后执行上述命令。'));
        } else {
            $this->printer->note('  kill -9 ' . $pid);
        }
    }

    /**
     * 检查进程是否正在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }

    /**
     * 清理服务器配置（仅移除运行时字段，保留用户配置如 worker_count、port、mode 等）
     */
    private function clearServerConfig(): void
    {
        $env = Env::getInstance();
        $server = $env->get('server');
        if (!\is_array($server)) {
            return;
        }
        $runtimeKeys = ['pid', 'start_time', 'status'];
        $cleaned = $server;
        foreach ($runtimeKeys as $key) {
            unset($cleaned[$key]);
        }
        $env->setConfig('server', $cleaned);
        $env->save();
    }

    /**
     * 保存服务器进程ID到环境配置（与现有 server 配置合并，不覆盖 worker_count 等用户配置）
     */
    private function saveServerPid(string $host, int $port, int $pid): void
    {
        $env = Env::getInstance();
        $existing = $env->get('server');
        $serverConfig = \is_array($existing) ? $existing : [];
        $serverConfig['host'] = $host;
        $serverConfig['port'] = $port;
        $serverConfig['pid'] = $pid;
        $serverConfig['start_time'] = time();
        $serverConfig['status'] = 'running';

        $env->setConfig('server', $serverConfig);
        $env->save();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '启用PHP内置本地WebServer服务。开发专用，请勿用于生产环境。默认后台运行。-r/--force：重启（先停后起）；-f/--foreground：前台运行。';
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
    -r, --force             重启（先停止现有服务器再启动，即强制重启）
    -f, --foreground        前台运行（实时查看日志输出）
    --host=<主机>           指定主机地址（默认：127.0.0.1；直连外网用 --host 0.0.0.0；-h 保留给帮助）
    -p, --port=<端口>       指定端口（默认：9981）
    --help                  显示此帮助信息

📋 使用方式：

1️⃣ 默认启动（后台运行）：
    php bin/w server:start                # 默认后台运行
    php bin/w server:start -p 8080        # 指定端口后台运行

2️⃣ 前台运行（查看实时日志）：
    php bin/w server:start -f             # 前台运行，实时查看日志
    php bin/w server:start --foreground   # 前台运行（完整参数名）

3️⃣ 重启（先停后起）：
    php bin/w server:start -r             # 重启（-r = 先停后起）
    php bin/w server:start --force        # 同上（完整参数名）
    php bin/w server:start -r -f          # 重启并前台运行（-f = 前台）

4️⃣ 自定义配置：
    php bin/w server:start --host 0.0.0.0 -p 8080    # 监听所有网卡，端口8080

💡 提示：
    - 后台模式：适合日常开发，不阻塞终端
    - 前台模式：适合调试，可以实时查看请求日志
    - 使用 "php bin/w server:stop" 停止后台运行的服务器
    - 使用 "php bin/w server:status" 查看服务器状态

════════════════════════════════════════════════════════════════════════════════
';
    }
}
