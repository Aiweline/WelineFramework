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
        
        // 压测参数（仅核心参数需要用户指定）
        $concurrency = (int) ($args['concurrency'] ?? $args['c'] ?? 100);
        $totalRequests = (int) ($args['requests'] ?? $args['n'] ?? 10000);
        $path = $args['path'] ?? '/';
        
        $this->printer->note(__('Weline Server 压力测试'));
        echo "\n";
        
        // 显示探测到的服务器信息
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                     压测目标                                   ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  目标地址：%-50s║', "http://{$host}:{$port}{$path}"));
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
        
        // 直接运行压测
        $this->runBenchmark($host, $port, $concurrency, $totalRequests, $path);
    }
    
    /**
     * 自动探测运行中的服务器
     */
    protected function detectRunningServer(array $args): ?array
    {
        // 1. 如果用户指定了端口，直接使用
        if (isset($args['port']) || isset($args['p'])) {
            $port = (int) ($args['port'] ?? $args['p']);
            $host = $args['host'] ?? $args['h'] ?? '127.0.0.1';
            return [
                'host' => $host,
                'port' => $port,
                'instance' => __('手动指定'),
                'worker_count' => 1,
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
                        // 兼容 count 和 worker_count 字段
                        $data['worker_count'] = $data['worker_count'] ?? $data['count'] ?? 1;
                        $instances[$name] = $data;
                    }
                }
            }
        }
        
        // 3. 从 env.server 读取配置
        $envConfig = Env::getInstance()->getConfig();
        if (isset($envConfig['server']['port'])) {
            $host = $envConfig['server']['host'] ?? '127.0.0.1';
            $port = (int) $envConfig['server']['port'];
            $socket = @\fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket) {
                \fclose($socket);
                if (!isset($instances['default'])) {
                    $instances['env'] = [
                        'host' => $host,
                        'port' => $port,
                        'worker_count' => $envConfig['server']['worker_count'] ?? 1,
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
        
        // 6. 只有一个实例，直接使用
        if (\count($instances) === 1) {
            $name = \array_key_first($instances);
            $data = $instances[$name];
            return [
                'host' => $data['host'],
                'port' => $data['port'],
                'instance' => $name,
                'worker_count' => $data['worker_count'] ?? 1,
            ];
        }
        
        // 7. 多个实例，显示列表让用户选择或使用 default
        if (isset($instances['default'])) {
            $data = $instances['default'];
            $this->printer->note(__('检测到多个服务器实例，使用默认实例 [default]'));
            return [
                'host' => $data['host'],
                'port' => $data['port'],
                'instance' => 'default',
                'worker_count' => $data['worker_count'] ?? 1,
            ];
        }
        
        // 使用第一个实例
        $name = \array_key_first($instances);
        $data = $instances[$name];
        $this->printer->note(__('检测到多个服务器实例，使用实例 [%{1}]', [$name]));
        return [
            'host' => $data['host'],
            'port' => $data['port'],
            'instance' => $name,
            'worker_count' => $data['worker_count'] ?? 1,
        ];
    }
    
    /**
     * 运行压测
     */
    protected function runBenchmark(string $host, int $port, int $concurrency, int $totalRequests, string $path): void
    {
        $url = "http://{$host}:{$port}{$path}";
        
        $results = [];
        $errors = 0;
        $startTime = \microtime(true);
        
        // 检查 curl 扩展
        if (!\function_exists('curl_multi_init')) {
            $this->printer->error(__('需要 curl 扩展支持'));
            return;
        }
        
        $mh = \curl_multi_init();
        $handles = [];
        $completed = 0;
        $requestsSent = 0;
        
        // 初始化并发请求
        $batchSize = \min($concurrency, $totalRequests);
        for ($i = 0; $i < $batchSize; $i++) {
            $ch = \curl_init($url);
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            \curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['handle' => $ch, 'start' => \microtime(true)];
            $requestsSent++;
        }
        
        $running = null;
        $lastProgress = 0;
        
        do {
            // 执行请求
            do {
                $status = \curl_multi_exec($mh, $running);
            } while ($status == CURLM_CALL_MULTI_PERFORM);
            
            // 检查完成的请求
            while ($info = \curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                
                if (isset($handles[$key])) {
                    $elapsed = (\microtime(true) - $handles[$key]['start']) * 1000; // ms
                    
                    if ($info['result'] === CURLE_OK) {
                        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if ($httpCode >= 200 && $httpCode < 400) {
                            $results[] = $elapsed;
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
                    
                    \curl_multi_remove_handle($mh, $ch);
                    \curl_close($ch);
                    unset($handles[$key]);
                    
                    // 添加新请求
                    if ($requestsSent < $totalRequests) {
                        $newCh = \curl_init($url);
                        \curl_setopt_array($newCh, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_CONNECTTIMEOUT => 5,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        ]);
                        \curl_multi_add_handle($mh, $newCh);
                        $handles[(int)$newCh] = ['handle' => $newCh, 'start' => \microtime(true)];
                        $requestsSent++;
                    }
                }
            }
            
            // 等待活动
            if ($running > 0) {
                \curl_multi_select($mh, 0.01);
            }
            
        } while ($running > 0 || \count($handles) > 0);
        
        \curl_multi_close($mh);
        
        $endTime = \microtime(true);
        $totalTime = $endTime - $startTime;
        
        // 生成报告
        $this->generateReport($results, $errors, $totalTime, $totalRequests);
    }
    
    /**
     * 生成报告
     */
    protected function generateReport(array $results, int $errors, float $totalTime, int $totalRequests): void
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
        ];
        
        $reportDir = BP . 'var/log';
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
                '--path <path>' => __('请求路径（默认：/）'),
                '-p, --port <port>' => __('指定端口（可选，默认自动探测）'),
                '-h, --host <ip>' => __('指定主机（可选，默认 127.0.0.1）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本压测（自动探测）') => 'php bin/w server:benchmark',
                __('高并发') => 'php bin/w server:benchmark -c 500 -n 50000',
                __('指定端口') => 'php bin/w server:benchmark -p 9000',
            ]
        );
    }
}
