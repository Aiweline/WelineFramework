<?php
declare(strict_types=1);

/**
 * Weline Server - 压测命令
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\App\Env;

/**
 * server:benchmark - 运行压力测试
 */
class Benchmark extends CommandAbstract
{
    private const DEFAULT_BENCHMARK_PATH = '/_wls/health';

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 自动探测服务器配置
        $serverConfig = $this->detectRunningServer($args);
        
        if (!$serverConfig) {
            return;
        }
        
        $host = $serverConfig['host'];
        $port = $serverConfig['port'];
        $instanceName = $serverConfig['instance'];
        $workerCount = $serverConfig['worker_count'];
        $ssl = (bool)($serverConfig['ssl'] ?? false);
        
        // 压测参数（仅核心参数需要用户指定）
        $concurrency = (int) ($args['concurrency'] ?? $args['c'] ?? 100);
        $totalRequests = (int) ($args['requests'] ?? $args['n'] ?? 10000);
        $path = $this->resolveBenchmarkPath($args);
        // keep-alive 会导致 Dispatcher 按 TCP 连接粘滞到某个 Worker；压测分流时可禁用连接复用
        $noKeepAlive = isset($args['no-keepalive']) || isset($args['no_keepalive']) || isset($args['spread']);
        // 命中 Worker 统计：支持自定义响应头（逗号分隔），默认自动探测常见 WLS 头
        $workerHeader = (string)($args['worker-header'] ?? $args['worker_header'] ?? '');
        $workerBalanceThreshold = (float)($args['worker-balance-threshold'] ?? $args['worker_balance_threshold'] ?? 1.5);
        if ($workerBalanceThreshold < 1.0) {
            $workerBalanceThreshold = 1.0;
        }
        
        // 修复 Git Bash 路径转换问题（如 /_wls/health 被转成 C:/Program Files/Git/_wls/health）
        $scheme = $ssl ? 'https' : 'http';
        $targetUrl = "{$scheme}://{$host}:{$port}{$path}";
        
        $this->printer->note(__('Weline Server 压力测试'));
        echo "\n";
        if (!isset($args['path']) || \trim((string)$args['path']) === '') {
            $this->printer->note(__('未指定 --path，默认使用轻量端点 %{1} 测 WLS 吞吐；压业务页请显式传 --path /xxx', [self::DEFAULT_BENCHMARK_PATH]));
            echo "\n";
        }
        
        // 显示探测到的服务器信息
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                     压测目标                                   ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  目标地址：%-50s║', $targetUrl));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', $workerCount));
        $this->printer->note(\sprintf('║  并发数：%-52s║', $concurrency));
        $this->printer->note(\sprintf('║  总请求数：%-50s║', $totalRequests));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // 检查服务器是否运行
        $socket = @\fsockopen($host, $port, $errno, $errstr, 5);
        if (!$socket) {
            $this->printer->error(__('无法连接到服务器 %{1}:%{2}', [$host, $port]));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        \fclose($socket);
        
        $this->printer->success(__('服务器连接成功，开始压测...'));
        echo "\n";
        
        // 直接运行压测（传入是否 HTTPS）
        $this->runBenchmark($targetUrl, $concurrency, $totalRequests, $ssl, $noKeepAlive, $workerHeader, $workerBalanceThreshold);
    }
    
    /**
     * 修复 Git Bash 路径转换问题
     * 
     * Git Bash 会自动将 /path 转换为 C:/Program Files/Git/path
     * 此方法检测并还原为正确的 URL 路径
     */
    protected function fixGitBashPath(string $path): string
    {
        // 检测常见的 Git Bash 路径前缀
        $gitBashPrefixes = [
            'C:/Program Files/Git/',
            'C:\\Program Files\\Git\\',
            '/c/Program Files/Git/',
            'D:/Program Files/Git/',
            'D:\\Program Files\\Git\\',
            '/d/Program Files/Git/',
        ];
        
        foreach ($gitBashPrefixes as $prefix) {
            if (\stripos($path, $prefix) === 0) {
                // 提取原始路径并还原
                $originalPath = \substr($path, \strlen($prefix) - 1);
                // 确保以 / 开头
                if ($originalPath[0] !== '/') {
                    $originalPath = '/' . $originalPath;
                }
                // 将反斜杠转换为正斜杠
                $originalPath = \str_replace('\\', '/', $originalPath);
                
                return $originalPath;
            }
        }
        
        // 确保路径以 / 开头
        if (!empty($path) && $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        return $path;
    }
    
    /**
     * 自动探测运行中的服务器
     */
    protected function resolveBenchmarkPath(array $args): string
    {
        $path = (string)($args['path'] ?? self::DEFAULT_BENCHMARK_PATH);
        $path = \trim($path);
        if ($path === '') {
            $path = self::DEFAULT_BENCHMARK_PATH;
        }

        return $this->fixGitBashPath($path);
    }

    /**
     * 鑷姩鎺㈡祴杩愯涓殑鏈嶅姟鍣?
     */
    protected function detectRunningServer(array $args): ?array
    {
        // 1. 如果用户指定了端口，直接使用（可加 --ssl 表示 HTTPS）
        if (isset($args['port']) || isset($args['p'])) {
            $port = (int) ($args['port'] ?? $args['p']);
            $host = $args['host'] ?? $args['h'] ?? '127.0.0.1';
            $ssl = isset($args['ssl']) || isset($args['s']);
            return [
                'host' => $host,
                'port' => $port,
                'instance' => __('手动指定'),
                'worker_count' => 1,
                'ssl' => $ssl,
            ];
        }
        
        // 2. 从实例文件探测
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances';
        $instances = [];
        
        if (\is_dir($instanceDir)) {
            $files = \glob($instanceDir . DS . '*.json');
            foreach ($files as $file) {
                $data = @\json_decode(\file_get_contents($file), true);
                if ($data && isset($data['host'], $data['port'])) {
                    $name = \basename($file, '.json');
                    // 检查端口是否在监听
                    $socket = @\fsockopen($data['host'], $data['port'], $errno, $errstr, 1);
                    if ($socket) {
                        \fclose($socket);
                        // 兼容 count 和 worker_count 字段；记录是否 HTTPS（压测需用 https://）
                        $data['worker_count'] = $data['worker_count'] ?? $data['count'] ?? 1;
                        $data['ssl'] = (bool)($data['ssl_enabled'] ?? false);
                        $instances[$name] = $data;
                    }
                }
            }
        }
        
        // 3. 从 wls 读取配置
        $envConfig = Env::getInstance()->getConfig();
        if (isset(($envConfig['wls'] ?? [])['port'])) {
            $host = ($envConfig['wls'] ?? [])['host'] ?? '127.0.0.1';
            $port = (int) $envConfig['wls']['port'];
            $socket = @\fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket) {
                \fclose($socket);
                if (!isset($instances['default'])) {
                    $instances['env'] = [
                        'host' => $host,
                        'port' => $port,
                        'worker_count' => ($envConfig['wls'] ?? [])['worker_count'] ?? 1,
                        'ssl' => (bool)(($envConfig['wls'] ?? [])['ssl_enabled'] ?? false),
                    ];
                }
            }
        }
        
        // 4. 检查默认端口 9981
        if (empty($instances)) {
            $defaultHost = '127.0.0.1';
            $defaultPort = 9981;
            $socket = @\fsockopen($defaultHost, $defaultPort, $errno, $errstr, 1);
            if ($socket) {
                \fclose($socket);
                $instances['default'] = [
                    'host' => $defaultHost,
                    'port' => $defaultPort,
                    'worker_count' => 1,
                    'ssl' => false,
                ];
            }
        }
        
        // 5. 没有找到运行中的服务器
        if (empty($instances)) {
            $this->printer->error(__('未检测到运行中的服务器'));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            echo "\n";
            $this->printer->note(__('或手动指定端口：php bin/w server:benchmark -p <port>'));
            return null;
        }
        
        $pick = function (array $data, string $name) {
            return [
                'host' => $data['host'],
                'port' => $data['port'],
                'instance' => $name,
                'worker_count' => $data['worker_count'] ?? 1,
                'ssl' => (bool)($data['ssl'] ?? false),
            ];
        };
        
        // 6. 只有一个实例，直接使用
        if (\count($instances) === 1) {
            $name = \array_key_first($instances);
            return $pick($instances[$name], $name);
        }
        
        // 7. 多个实例，显示列表让用户选择或使用 default
        if (isset($instances['default'])) {
            $this->printer->note(__('检测到多个服务器实例，使用默认实例 [default]'));
            return $pick($instances['default'], 'default');
        }
        
        // 使用第一个实例
        $name = \array_key_first($instances);
        $this->printer->note(__('检测到多个服务器实例，使用实例 [%{1}]', [$name]));
        return $pick($instances[$name], $name);
    }
    
    /**
     * 运行压测
     *
     * @param string $url 完整目标 URL（含 http/https）
     * @param int $concurrency 并发数
     * @param int $totalRequests 总请求数
     * @param bool $ssl 是否 HTTPS（用于设置 SSL 验证选项，本地自签证书可跳过验证）
     */
    protected function runBenchmark(
        string $url,
        int $concurrency,
        int $totalRequests,
        bool $ssl = false,
        bool $noKeepAlive = false,
        string $workerHeader = '',
        float $workerBalanceThreshold = 1.5
    ): void
    {
        $results = [];
        $errors = 0;
        $workerHits = [];
        $startTime = \microtime(true);
        
        // 检查 curl 扩展
        if (!\function_exists('curl_multi_init')) {
            $this->printer->error(__('需要 curl 扩展支持'));
            return;
        }
        
        // 基础选项
        $baseOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        if ($noKeepAlive) {
            // 分流压测模式：每个请求尽量新建连接，让 Dispatcher 在“连接级”重新选择 Worker
            $baseOpts[CURLOPT_FORBID_REUSE] = true;
            $baseOpts[CURLOPT_FRESH_CONNECT] = true;
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 0;
            $baseOpts[CURLOPT_HTTPHEADER] = [
                'Connection: close',
            ];
        } else {
            // 性能压测模式：启用连接复用（Keep-Alive）
            $baseOpts[CURLOPT_FORBID_REUSE] = false;      // 允许连接复用
            $baseOpts[CURLOPT_FRESH_CONNECT] = false;     // 不强制新连接
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 1;         // 启用 TCP Keep-Alive
            $baseOpts[CURLOPT_TCP_KEEPIDLE] = 60;         // Keep-Alive 空闲时间
            $baseOpts[CURLOPT_TCP_KEEPINTVL] = 30;        // Keep-Alive 间隔
            $baseOpts[CURLOPT_HTTPHEADER] = [
                'Connection: keep-alive',
                'Keep-Alive: timeout=60, max=1000',
            ];
        }
        if ($ssl) {
            $baseOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $baseOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        
        $mh = \curl_multi_init();
        
        // 创建共享句柄，用于连接池复用（禁用 keep-alive 时不启用共享池）
        $sh = null;
        if (!$noKeepAlive) {
            $sh = \curl_share_init();
            \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
            \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            if (\defined('CURL_LOCK_DATA_SSL_SESSION')) {
                \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
            }
        }
        
        // 设置 curl_multi 管道化/复用（HTTP/1.1 管道化，HTTP/2 多路复用）
        if (\defined('CURLPIPE_MULTIPLEX')) {
            \curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
        // 限制每个主机的最大连接数，促进连接复用
        if (\defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
            \curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, $concurrency);
        }
        
        // 创建固定数量的 curl handle 用于复用
        $handlePool = [];
        $activeHandles = [];  // key => ['handle' => $ch, 'start' => time, 'poolIndex' => index]
        $headerBuffers = [];  // key => raw header text
        $completed = 0;
        $requestsSent = 0;
        
        $batchSize = \min($concurrency, $totalRequests);
        
        // 初始化 handle 池（绑定共享句柄）
        for ($i = 0; $i < $batchSize; $i++) {
            $ch = \curl_init();
            \curl_setopt_array($ch, $baseOpts);
            \curl_setopt($ch, CURLOPT_URL, $url);
            if ($sh !== null) {
                \curl_setopt($ch, CURLOPT_SHARE, $sh);  // 共享连接池
            }
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($chRef, string $line) use (&$headerBuffers): int {
                $headerBuffers[(int)$chRef] = ($headerBuffers[(int)$chRef] ?? '') . $line;
                return \strlen($line);
            });
            $handlePool[$i] = $ch;
        }
        
        // 添加初始批次请求
        for ($i = 0; $i < $batchSize; $i++) {
            $ch = $handlePool[$i];
            \curl_multi_add_handle($mh, $ch);
            $activeHandles[(int)$ch] = [
                'handle' => $ch,
                'start' => \microtime(true),
                'poolIndex' => $i,
            ];
            $requestsSent++;
        }
        
        $running = null;
        $lastProgress = 0;
        
        $this->printer->note($noKeepAlive
            ? __('压测模式：禁用 keep-alive（更利于分流验证），并发连接数=%{1}', [$batchSize])
            : __('压测模式：启用 keep-alive（性能模式），使用 %{1} 个持久连接...', [$batchSize]));
        
        do {
            // 执行请求
            do {
                $status = \curl_multi_exec($mh, $running);
            } while ($status == CURLM_CALL_MULTI_PERFORM);
            
            // 检查完成的请求
            while ($info = \curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                
                if (isset($activeHandles[$key])) {
                    $elapsed = (\microtime(true) - $activeHandles[$key]['start']) * 1000; // ms
                    $poolIndex = $activeHandles[$key]['poolIndex'];
                    
                    if ($info['result'] === CURLE_OK) {
                        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if ($httpCode >= 200 && $httpCode < 400) {
                            $results[] = $elapsed;
                            $headers = $this->parseResponseHeaders($headerBuffers[$key] ?? '');
                            $workerMarker = $this->extractWorkerMarker($headers, $workerHeader);
                            if ($workerMarker !== '') {
                                $workerHits[$workerMarker] = ($workerHits[$workerMarker] ?? 0) + 1;
                            }
                        } else {
                            $errors++;
                        }
                    } else {
                        $errors++;
                    }
                    
                    $completed++;
                    
                    // 显示进度
                    $progress = (int)($completed / $totalRequests * 100);
                    if ($progress >= $lastProgress + 10) {
                        $this->printer->note(__('进度：%{1}% (%{2}/%{3})', [$progress, $completed, $totalRequests]));
                        $lastProgress = $progress;
                    }
                    
                    // 从 multi handle 移除
                    \curl_multi_remove_handle($mh, $ch);
                    unset($activeHandles[$key]);
                    $headerBuffers[$key] = '';
                    
                    // 如果还有请求要发送，复用同一个 handle（共享连接池会自动复用连接）
                    if ($requestsSent < $totalRequests) {
                        // 重新添加到 multi handle（共享句柄会复用连接）
                        \curl_multi_add_handle($mh, $ch);
                        $activeHandles[(int)$ch] = [
                            'handle' => $ch,
                            'start' => \microtime(true),
                            'poolIndex' => $poolIndex,
                        ];
                        $requestsSent++;
                    }
                }
            }
            
            // 等待活动
            if ($running > 0) {
                \curl_multi_select($mh, 0.01);
            }
            
        } while ($running > 0 || \count($activeHandles) > 0);
        
        // 清理 handle 池和共享句柄
        foreach ($handlePool as $ch) {
            \curl_close($ch);
        }
        \curl_multi_close($mh);
        if ($sh !== null) {
            \curl_share_close($sh);
        }
        
        $endTime = \microtime(true);
        $totalTime = $endTime - $startTime;
        
        // 生成报告
        $this->generateReport($results, $errors, $totalTime, $totalRequests, $workerHits, $workerBalanceThreshold);
    }
    
    /**
     * 生成报告
     */
    protected function generateReport(
        array $results,
        int $errors,
        float $totalTime,
        int $totalRequests,
        array $workerHits = [],
        float $workerBalanceThreshold = 1.5
    ): void
    {
        $successCount = \count($results);
        $totalCompleted = $successCount + $errors;
        
        if (!empty($results)) {
            \sort($results);
            
            $avgTime = \array_sum($results) / \count($results);
            $minTime = \min($results);
            $maxTime = \max($results);
            $medianTime = $results[(int)(\count($results) / 2)];
            $p95Index = \min((int)(\count($results) * 0.95), \count($results) - 1);
            $p99Index = \min((int)(\count($results) * 0.99), \count($results) - 1);
            $p95Time = $results[$p95Index];
            $p99Time = $results[$p99Index];
        } else {
            $avgTime = $minTime = $maxTime = $medianTime = $p95Time = $p99Time = 0;
        }
        
        $qps = $totalTime > 0 ? $successCount / $totalTime : 0;
        $errorRate = $totalCompleted > 0 ? ($errors / $totalCompleted) * 100 : 0;
        
        echo "\n";
        $this->printer->setup(__('压测结果报告'));
        echo "\n";
        
        $this->printer->note(__('总请求数：%{1}', [$totalCompleted]));
        $this->printer->success(__('成功请求：%{1}', [$successCount]));
        if ($errors > 0) {
            $this->printer->error(__('失败请求：%{1}', [$errors]));
        } else {
            $this->printer->note(__('失败请求：%{1}', [$errors]));
        }
        $this->printer->note(__('错误率：%{1}%', [\round($errorRate, 2)]));
        
        echo "\n";
        $this->printer->note(__('总耗时：%{1} 秒', [\round($totalTime, 3)]));
        $this->printer->success(__('QPS：%{1}', [\round($qps, 2)]));
        
        echo "\n";
        $this->printer->setup(__('延迟统计（毫秒）'));
        echo "\n";
        $this->printer->note(__('平均：%{1}', [\round($avgTime, 3)]));
        $this->printer->note(__('最小：%{1}', [\round($minTime, 3)]));
        $this->printer->note(__('最大：%{1}', [\round($maxTime, 3)]));
        $this->printer->note(__('中位数：%{1}', [\round($medianTime, 3)]));
        $this->printer->note(__('P95：%{1}', [\round($p95Time, 3)]));
        $this->printer->note(__('P99：%{1}', [\round($p99Time, 3)]));
        $workerBalance = null;
        if (!empty($workerHits)) {
            \arsort($workerHits);
            echo "\n";
            $this->printer->setup(__('Worker 命中分布'));
            echo "\n";
            $sum = \array_sum($workerHits);
            foreach ($workerHits as $worker => $count) {
                $ratio = $sum > 0 ? \round($count * 100 / $sum, 2) : 0.0;
                $this->printer->note(__('%{1}：%{2} (%{3}%)', [$worker, $count, $ratio]));
            }

            $max = (int)\max($workerHits);
            $min = (int)\min($workerHits);
            $spreadRatio = $min > 0 ? $max / $min : INF;
            $balanced = $spreadRatio <= $workerBalanceThreshold;
            $workerBalance = [
                'threshold' => \round($workerBalanceThreshold, 3),
                'max' => $max,
                'min' => $min,
                'spread_ratio' => \is_finite($spreadRatio) ? \round($spreadRatio, 3) : INF,
                'balanced' => $balanced,
            ];
            echo "\n";
            if ($balanced) {
                $this->printer->success(__('分流均衡检查：OK（max/min=%{1}，阈值=%{2}）', [
                    (string)$workerBalance['spread_ratio'],
                    (string)$workerBalance['threshold'],
                ]));
            } else {
                $this->printer->warning(__('分流均衡检查：WARN（max/min=%{1}，阈值=%{2}）', [
                    (string)$workerBalance['spread_ratio'],
                    (string)$workerBalance['threshold'],
                ]));
            }
        }
        
        echo "\n";
        
        // 保存报告
        $report = [
            'total_requests' => $totalCompleted,
            'success_count' => $successCount,
            'error_count' => $errors,
            'error_rate' => \round($errorRate, 2),
            'total_time_seconds' => \round($totalTime, 3),
            'qps' => \round($qps, 2),
            'latency_ms' => [
                'avg' => \round($avgTime, 3),
                'min' => \round($minTime, 3),
                'max' => \round($maxTime, 3),
                'median' => \round($medianTime, 3),
                'p95' => \round($p95Time, 3),
                'p99' => \round($p99Time, 3),
            ],
            'worker_hits' => $workerHits,
            'worker_balance' => $workerBalance,
        ];
        
        $reportDir = BP . 'var/log/wls';
        if (!\is_dir($reportDir)) {
            @\mkdir($reportDir, 0755, true);
        }
        $reportFile = $reportDir . '/benchmark_report_' . \date('Ymd_His') . '.json';
        \file_put_contents($reportFile, \json_encode($report, JSON_PRETTY_PRINT));
        $this->printer->note(__('报告已保存：%{1}', [$reportFile]));
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('对 Weline Server 进行压力测试');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:benchmark',
            __('自动探测运行中的服务器并进行压力测试'),
            [
                '-c, --concurrency <n>' => __('并发数（默认：100）'),
                '-n, --requests <n>' => __('总请求数（默认：10000）'),
                '--path <path>' => __('请求路径（默认：/_wls/health）'),
                '-p, --port <port>' => __('指定端口（可选，默认自动探测）'),
                '-h, --host <ip>' => __('指定主机（可选，默认 127.0.0.1）'),
                '-s, --ssl' => __('指定端口为 HTTPS（与 -p 合用；自动探测时根据实例配置）'),
                '--no-keepalive, --spread' => __('禁用 keep-alive/连接复用（更利于验证 Dispatcher 分流；开销更大）'),
                '--worker-header <name>' => __('命中 Worker 统计使用的响应头（可逗号分隔；默认自动探测 X-WLS-Worker-Port/Id/PID）'),
                '--worker-balance-threshold <ratio>' => __('分流倾斜阈值，按 max/min 判定（默认 1.5，超过则 WARN）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本压测（自动探测）') => 'php bin/w server:benchmark',
                __('高并发') => 'php bin/w server:benchmark -c 500 -n 50000',
                __('分流验证（禁用 keep-alive）') => 'php bin/w server:benchmark -c 500 -n 50000 --no-keepalive',
                __('统计 Worker 分布') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-header X-WLS-Worker-Port',
                __('分流倾斜阈值检查') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-balance-threshold 1.3',
                __('指定端口') => 'php bin/w server:benchmark -p 9000',
                __('指定 HTTPS 端口') => 'php bin/w server:benchmark -p 9443 --ssl',
            ]
        );
    }

    private function parseResponseHeaders(string $rawHeaders): array
    {
        if ($rawHeaders === '') {
            return [];
        }
        // 多次重定向/1xx 时只取最后一段响应头
        $blocks = \preg_split("/\r\n\r\n|\n\n/", \trim($rawHeaders));
        $lastBlock = (string)($blocks[\count($blocks) - 1] ?? '');
        $lines = \preg_split("/\r\n|\n/", $lastBlock) ?: [];
        $headers = [];
        foreach ($lines as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = \strtolower(\trim(\substr($line, 0, $pos)));
            $value = \trim(\substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function extractWorkerMarker(array $headers, string $workerHeader): string
    {
        $candidates = [];
        if ($workerHeader !== '') {
            $candidates = \array_values(\array_filter(\array_map('trim', \explode(',', $workerHeader))));
        }
        if (empty($candidates)) {
            $candidates = ['X-WLS-Worker-Port', 'X-WLS-Worker-Id', 'X-WLS-Worker-PID'];
        }
        foreach ($candidates as $headerName) {
            $key = \strtolower($headerName);
            if (!isset($headers[$key]) || $headers[$key] === '') {
                continue;
            }
            return $headerName . '=' . $headers[$key];
        }
        return '';
    }
}
