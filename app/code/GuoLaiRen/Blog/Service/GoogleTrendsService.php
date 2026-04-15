<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * Google Trends 数据拉取（SerpApi + 官方占位）
 */

namespace GuoLaiRen\Blog\Service;

use GuoLaiRen\Blog\Model\TrendsConfig;
use Weline\Framework\Runtime\SchedulerSystem;

class GoogleTrendsService
{
    private const SERPAPI_URL = 'https://serpapi.com/search';

    /**
     * 拉取关键词趋势数据（统一结构）
     *
     * @param string[] $keywords 关键词列表
     * @param array{region?: string, date?: string} $options region=US/CN, date=today 12-m 等
     * @return array<string, array{value: int, date: string, region: string}> keyword => [value, date, region]
     */
    public function fetchTrends(array $keywords, array $options = []): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords)));
        if (empty($keywords)) {
            return [];
        }

        if (TrendsConfig::useSerpApi()) {
            return $this->fetchViaSerpApi($keywords, $options);
        }

        if (TrendsConfig::useOfficialApi()) {
            return $this->fetchViaOfficialApi($keywords, $options);
        }

        return [];
    }

    /**
     * SerpApi 最多 5 个查询；多则分批
     */
    private function fetchViaSerpApi(array $keywords, array $options): array
    {
        $apiKey = TrendsConfig::get(TrendsConfig::KEY_SERPAPI_KEY);
        if ($apiKey === '') {
            throw new \RuntimeException(__('请先在 Trends 配置中填写 SerpApi API Key'));
        }

        $region = $options['region'] ?? TrendsConfig::get(TrendsConfig::KEY_REGION, 'US');
        $date = $options['date'] ?? 'today 12-m';

        $result = [];
        $chunks = array_chunk($keywords, 5);
        $isFirst = true;

        foreach ($chunks as $chunk) {
            if (!$isFirst) {
                SchedulerSystem::sleep(1);
            }
            $isFirst = false;
            $q = implode(',', $chunk);
            $params = [
                'engine' => 'google_trends',
                'q' => $q,
                'api_key' => $apiKey,
                'data_type' => 'TIMESERIES',
                'geo' => $region,
                'date' => $date,
            ];
            $url = self::SERPAPI_URL . '?' . http_build_query($params);
            $json = $this->httpGetWithRetry($url);

            if ($json === '') {
                continue;
            }

            $data = json_decode($json, true);
            if (!is_array($data) || isset($data['error'])) {
                if (isset($data['error'])) {
                    trigger_error(__('Google Trends SerpApi 错误：%{error}', ['error' => is_string($data['error']) ? $data['error'] : json_encode($data['error'])]), E_USER_WARNING);
                }
                continue;
            }

            $timeline = $data['interest_over_time']['timeline_data'] ?? null;
            if (!is_array($timeline) || empty($timeline)) {
                continue;
            }

            $latest = end($timeline);
            $timestamp = isset($latest['timestamp']) ? (int)$latest['timestamp'] : 0;
            $dateStr = $timestamp > 0 ? date('Y-m-d', $timestamp) : date('Y-m-d');

            foreach ($latest['values'] ?? [] as $item) {
                $query = $item['query'] ?? '';
                $value = (int)($item['extracted_value'] ?? $item['value'] ?? 0);
                if ($query !== '') {
                    $result[$query] = [
                        'value' => $value,
                        'date' => $dateStr,
                        'region' => $region,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 官方 API（Service Account）占位：未实现则返回空
     */
    private function fetchViaOfficialApi(array $keywords, array $options): array
    {
        $json = TrendsConfig::get(TrendsConfig::KEY_SERVICE_ACCOUNT_JSON);
        if ($json === '') {
            throw new \RuntimeException(__('请先在 Trends 配置中填写 Service Account JSON'));
        }

        // 官方 Google Trends API 2025 alpha 需单独对接，此处暂返回空，避免报错
        return [];
    }

    /**
     * 拉取趋势并带上期数据（用于计算日环比、周环比）
     *
     * @param string[] $keywords
     * @param array{region?: string} $options
     * @return array<string, array{current: int, previous_day: int, previous_week: int, date: string}>
     */
    public function fetchTrendsWithHistory(array $keywords, array $options = []): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords)));
        if (empty($keywords) || !TrendsConfig::useSerpApi()) {
            return [];
        }

        $apiKey = TrendsConfig::get(TrendsConfig::KEY_SERPAPI_KEY);
        if ($apiKey === '') {
            return [];
        }

        $region = $options['region'] ?? TrendsConfig::get(TrendsConfig::KEY_REGION, 'US');
        $result = [];
        $chunks = array_chunk($keywords, 5);

        $isFirst = true;
        foreach ($chunks as $chunk) {
            if (!$isFirst) {
                SchedulerSystem::sleep(1);
            }
            $isFirst = false;
            $q = implode(',', $chunk);
            $params = [
                'engine' => 'google_trends',
                'q' => $q,
                'api_key' => $apiKey,
                'data_type' => 'TIMESERIES',
                'geo' => $region,
                'date' => 'today 1-m',
            ];
            $url = self::SERPAPI_URL . '?' . http_build_query($params);
            $json = $this->httpGetWithRetry($url);
            if ($json === '') {
                continue;
            }
            $data = json_decode($json, true);
            $timeline = $data['interest_over_time']['timeline_data'] ?? null;
            if (!is_array($timeline) || count($timeline) < 2) {
                continue;
            }
            $latest = end($timeline);
            $idxDay = count($timeline) - 2;
            $idxWeek = max(0, count($timeline) - 8);
            $previousDay = $timeline[$idxDay] ?? $timeline[0];
            $previousWeek = $timeline[$idxWeek] ?? $timeline[0];
            $timestamp = (int)($latest['timestamp'] ?? 0);
            $dateStr = $timestamp > 0 ? date('Y-m-d', $timestamp) : date('Y-m-d');

            foreach ($latest['values'] ?? [] as $item) {
                $query = $item['query'] ?? '';
                if ($query === '') {
                    continue;
                }
                $current = (int)($item['extracted_value'] ?? $item['value'] ?? 0);
                $prevDay = $this->getValueForQuery($previousDay['values'] ?? [], $query);
                $prevWeek = $this->getValueForQuery($previousWeek['values'] ?? [], $query);
                $result[$query] = [
                    'current' => $current,
                    'previous_day' => $prevDay,
                    'previous_week' => $prevWeek,
                    'date' => $dateStr,
                ];
            }
        }
        return $result;
    }

    private function getValueForQuery(array $values, string $query): int
    {
        foreach ($values as $pv) {
            if (($pv['query'] ?? '') === $query) {
                return (int)($pv['extracted_value'] ?? $pv['value'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * 按当前趋势值排序取 Top N
     *
     * @param array<string, array{value: int, date: string, region: string}> $trends
     * @return array<int, array{keyword: string, value: int, date: string, region: string}>
     */
    public function topByValue(array $trends, int $n = 10): array
    {
        uasort($trends, static function ($a, $b) {
            return ($b['value'] ?? 0) <=> ($a['value'] ?? 0);
        });
        $out = [];
        $i = 0;
        foreach ($trends as $keyword => $row) {
            if ($i >= $n) {
                break;
            }
            $out[] = [
                'keyword' => $keyword,
                'value' => (int)($row['value'] ?? 0),
                'date' => (string)($row['date'] ?? ''),
                'region' => (string)($row['region'] ?? ''),
            ];
            $i++;
        }
        return $out;
    }

    /**
     * HTTP GET，失败时重试一次并记录
     */
    private function httpGetWithRetry(string $url, int $maxAttempts = 2): string
    {
        $lastBody = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $lastBody = $this->httpGet($url);
            if ($lastBody !== '') {
                return $lastBody;
            }
            if ($attempt < $maxAttempts) {
                SchedulerSystem::yieldDelay(500);
            }
        }
        trigger_error(__('Google Trends 请求失败（已重试）：%{url}', ['url' => $url]), E_USER_WARNING);
        return '';
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) ? $body : '';
        }

        $ctx = stream_context_create(['http' => ['timeout' => 30]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
}
