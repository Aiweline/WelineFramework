<?php
declare(strict_types=1);

namespace Weline\Server\Service\Benchmark;

use Weline\Server\Model\ServerBenchmarkLog;

class ServerBenchmarkService
{
    public function __construct(
        private readonly ServerBenchmarkLog $benchmarkLog
    ) {
    }

    public function runAndStore(
        string $instanceName,
        string $targetUrl,
        int $concurrency,
        int $requests
    ): array {
        $result = $this->runBenchmark($targetUrl, $concurrency, $requests);

        $this->benchmarkLog->clearQuery()
            ->setData(ServerBenchmarkLog::schema_fields_INSTANCE, $instanceName)
            ->setData(ServerBenchmarkLog::schema_fields_TARGET_URL, $targetUrl)
            ->setData(ServerBenchmarkLog::schema_fields_CONCURRENCY, $concurrency)
            ->setData(ServerBenchmarkLog::schema_fields_REQUESTS, $requests)
            ->setData(ServerBenchmarkLog::schema_fields_QPS, (float)($result['qps'] ?? 0))
            ->setData(ServerBenchmarkLog::schema_fields_ERROR_RATE, (float)($result['error_rate'] ?? 0))
            ->setData(ServerBenchmarkLog::schema_fields_LATENCY_AVG, (float)($result['latency']['avg'] ?? 0))
            ->setData(ServerBenchmarkLog::schema_fields_LATENCY_P95, (float)($result['latency']['p95'] ?? 0))
            ->setData(ServerBenchmarkLog::schema_fields_LATENCY_P99, (float)($result['latency']['p99'] ?? 0))
            ->setData(ServerBenchmarkLog::schema_fields_RESULT_JSON, \json_encode($result, JSON_UNESCAPED_UNICODE))
            ->setData(ServerBenchmarkLog::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        return $result;
    }

    public function list(int $page = 1, int $limit = 20): array
    {
        return $this->benchmarkLog->clearQuery()
            ->order(ServerBenchmarkLog::schema_fields_ID, 'DESC')
            ->pagination(\max(1, $page), \max(1, $limit))
            ->select()
            ->fetchArray();
    }

    public function detail(int $benchmarkId): ?array
    {
        $row = $this->benchmarkLog->clearQuery()->where(ServerBenchmarkLog::schema_fields_ID, $benchmarkId)->find()->fetch();
        if (!\is_array($row)) {
            return null;
        }
        $json = (string)($row[ServerBenchmarkLog::schema_fields_RESULT_JSON] ?? '');
        $row['parsed_result'] = \json_decode($json, true) ?: [];
        return $row;
    }

    private function runBenchmark(string $url, int $concurrency, int $totalRequests): array
    {
        if (!\function_exists('curl_multi_init')) {
            return [
                'success' => false,
                'message' => (string)__('当前 PHP 环境未启用 curl 扩展，无法执行压测。'),
            ];
        }

        $concurrency = \max(1, \min(500, $concurrency));
        $totalRequests = \max(1, \min(200000, $totalRequests));

        $mh = \curl_multi_init();
        $handles = [];
        $active = [];
        $latencies = [];
        $errors = 0;
        $completed = 0;
        $sent = 0;
        $startedAt = \microtime(true);

        $batch = \min($concurrency, $totalRequests);
        for ($i = 0; $i < $batch; $i++) {
            $ch = \curl_init();
            \curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_HTTPHEADER => ['Connection: keep-alive'],
            ]);
            $handles[] = $ch;
        }

        foreach ($handles as $ch) {
            \curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = ['start' => \microtime(true), 'handle' => $ch];
            $sent++;
        }

        do {
            do {
                $state = \curl_multi_exec($mh, $running);
            } while ($state === CURLM_CALL_MULTI_PERFORM);

            while ($info = \curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                $start = (float)($active[$key]['start'] ?? \microtime(true));
                unset($active[$key]);

                $lat = (\microtime(true) - $start) * 1000;
                $httpCode = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($info['result'] !== CURLE_OK || $httpCode < 200 || $httpCode >= 500) {
                    $errors++;
                } else {
                    $latencies[] = $lat;
                }
                $completed++;

                \curl_multi_remove_handle($mh, $ch);
                if ($sent < $totalRequests) {
                    \curl_multi_add_handle($mh, $ch);
                    $active[(int)$ch] = ['start' => \microtime(true), 'handle' => $ch];
                    $sent++;
                }
            }

            if ($running > 0) {
                \curl_multi_select($mh, 0.02);
            }
        } while ($running > 0 || !empty($active));

        foreach ($handles as $ch) {
            \curl_close($ch);
        }
        \curl_multi_close($mh);

        $elapsedSec = \max(0.001, \microtime(true) - $startedAt);
        \sort($latencies);
        $count = \count($latencies);
        $avg = $count > 0 ? (\array_sum($latencies) / $count) : 0;
        $p95 = $count > 0 ? $latencies[\min($count - 1, (int)\floor($count * 0.95))] : 0;
        $p99 = $count > 0 ? $latencies[\min($count - 1, (int)\floor($count * 0.99))] : 0;

        return [
            'success' => true,
            'total_requests' => $totalRequests,
            'completed' => $completed,
            'errors' => $errors,
            'error_rate' => \round(($errors / \max(1, $completed)) * 100, 2),
            'elapsed_seconds' => \round($elapsedSec, 3),
            'qps' => \round(($completed - $errors) / $elapsedSec, 2),
            'latency' => [
                'avg' => \round($avg, 3),
                'p95' => \round((float)$p95, 3),
                'p99' => \round((float)$p99, 3),
                'max' => \round((float)($count > 0 ? $latencies[$count - 1] : 0), 3),
            ],
        ];
    }
}
