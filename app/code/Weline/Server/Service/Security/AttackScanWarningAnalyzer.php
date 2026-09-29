<?php

declare(strict_types=1);

namespace Weline\Server\Service\Security;

/**
 * 从 AttackLog 统计与近期行推导「扫描特征」警告（仅提示，不拦截）。
 */
final class AttackScanWarningAnalyzer
{
    public const PATH_SCAN_WARN = 5;
    public const PATH_SCAN_DANGER = 30;
    public const REQUEST_SHAPE_WARN = 1;
    public const REQUEST_SHAPE_DANGER = 10;
    public const MALICIOUS_WARN = 3;
    public const BAD_UA_WARN = 10;
    public const TOP_IP_WARN = 20;
    public const UNKNOWN_QUERY_WARN = 3;

    /** @var list<string> */
    private const SCAN_QUERY_MARKERS = [
        'wp-',
        'wordpress',
        'phpmyadmin',
        'phpunit',
        '.env',
        'actuator',
        'cgi-bin',
        'union%20',
        'information_schema',
        'base64_decode',
        'eval(',
        '/etc/passwd',
        'shell',
        'cmd=',
        'passwd=',
        'xmlrpc',
        'wlwmanifest',
        'alfa.php',
        'vendor/phpunit',
    ];

    /**
     * @param array<string, mixed> $stats AttackLog::getStatistics() 形态
     * @param list<array<string, mixed>> $recentRows
     * @return list<array{id:string,severity:string,code:string,title:string,message:string,count:int}>
     */
    public function analyze(array $stats, array $recentRows = []): array
    {
        $byType = \is_array($stats['by_type'] ?? null) ? $stats['by_type'] : [];
        $topIps = \is_array($stats['top_ips'] ?? null) ? $stats['top_ips'] : [];
        $bySeverity = \is_array($stats['by_severity'] ?? null) ? $stats['by_severity'] : [];

        $warnings = [];
        $featureCodes = [];

        $pathScan = $this->typeCount($byType, ['path_scan']);
        if ($pathScan >= self::PATH_SCAN_WARN) {
            $featureCodes['path_scan'] = true;
            $warnings[] = $this->warning(
                'path_scan',
                $pathScan >= self::PATH_SCAN_DANGER ? 'danger' : 'warning',
                'path_scan',
                (string)__('检测到路径扫描'),
                (string)__('近窗口内路径扫描事件 %{1} 次，疑似目录爆破。', [$pathScan]),
                $pathScan
            );
        }

        $shape = $this->typeCount($byType, ['request_shape']);
        if ($shape >= self::REQUEST_SHAPE_WARN) {
            $featureCodes['request_shape'] = true;
            $warnings[] = $this->warning(
                'request_shape',
                $shape >= self::REQUEST_SHAPE_DANGER ? 'danger' : 'warning',
                'request_shape',
                (string)__('检测到畸形请求目标'),
                (string)__('非法 path/query/编码请求 %{1} 次（常为扫描器探测）。', [$shape]),
                $shape
            );
        }

        $malicious = $this->typeCount($byType, [
            'malicious_pattern',
            'malicious_uri',
            'malicious_body',
        ]);
        if ($malicious >= self::MALICIOUS_WARN) {
            $featureCodes['malicious'] = true;
            $warnings[] = $this->warning(
                'malicious_payload',
                'warning',
                'malicious',
                (string)__('检测到恶意请求特征'),
                (string)__('恶意 URI/Body/模式命中 %{1} 次。', [$malicious]),
                $malicious
            );
        }

        $badUa = $this->typeCount($byType, ['bad_user_agent', 'crawler_block']);
        if ($badUa >= self::BAD_UA_WARN) {
            $featureCodes['bad_ua'] = true;
            $warnings[] = $this->warning(
                'bad_user_agent',
                'warning',
                'bad_ua',
                (string)__('检测到异常爬虫/UA'),
                (string)__('恶意 UA 或爬虫拦截 %{1} 次。', [$badUa]),
                $badUa
            );
        }

        $protected = $this->typeCount($byType, ['protected_path']);
        $banOnPathHits = $this->countReasonContains($recentRows, '扫描路径');
        $sensitive = $protected + $banOnPathHits;
        if ($sensitive >= 1) {
            $featureCodes['protected_path'] = true;
            $warnings[] = $this->warning(
                'protected_path',
                'warning',
                'protected_path',
                (string)__('命中敏感/扫描路径'),
                (string)__('敏感路径或扫描路径封禁相关事件 %{1} 次。', [$sensitive]),
                $sensitive
            );
        }

        $topIpCount = 0;
        $topIp = '';
        foreach ($topIps as $ip => $count) {
            $count = (int)$count;
            if ($count > $topIpCount) {
                $topIpCount = $count;
                $topIp = (string)$ip;
            }
        }
        if ($topIpCount >= self::TOP_IP_WARN && $topIp !== '') {
            $featureCodes['concentrated_ip'] = true;
            $warnings[] = $this->warning(
                'concentrated_ip',
                'warning',
                'concentrated_ip',
                (string)__('单 IP 高频攻击'),
                (string)__('IP %{1} 在统计窗口内出现 %{2} 次，疑似集中扫描。', [$topIp, $topIpCount]),
                $topIpCount
            );
        }

        $unknownQuery = $this->countUnknownQueryProbes($recentRows);
        if ($unknownQuery >= self::UNKNOWN_QUERY_WARN) {
            $featureCodes['unknown_query_probe'] = true;
            $warnings[] = $this->warning(
                'unknown_query_probe',
                'warning',
                'unknown_query_probe',
                (string)__('疑似未知参数扫描'),
                (string)__('近期请求 URI 中出现 %{1} 次可疑查询参数特征（仅统计提示，未自动拦截）。', [$unknownQuery]),
                $unknownQuery
            );
        }

        $critical = (int)($bySeverity['critical'] ?? 0);
        if ($critical >= 1) {
            $featureCodes['critical'] = true;
            $warnings[] = $this->warning(
                'severity_critical',
                'danger',
                'critical',
                (string)__('存在严重级别攻击'),
                (string)__('近窗口 critical 事件 %{1} 次，请尽快核对封禁与规则。', [$critical]),
                $critical
            );
        }

        if (\count($featureCodes) >= 2) {
            $warnings[] = $this->warning(
                'scan_campaign',
                'danger',
                'scan_campaign',
                (string)__('多类扫描特征并用'),
                (string)__('同时出现 %{1} 类扫描相关特征，更像有组织探测活动。', [\count($featureCodes)]),
                \count($featureCodes)
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $stats
     * @param list<array<string, mixed>> $recentRows
     * @return array<string, mixed>
     */
    public function enrichStatistics(array $stats, array $recentRows = []): array
    {
        $stats['scan_warnings'] = $this->analyze($stats, $recentRows);

        return $stats;
    }

    /**
     * @param array<string, int|string> $byType
     * @param list<string> $types
     */
    private function typeCount(array $byType, array $types): int
    {
        $sum = 0;
        foreach ($types as $type) {
            $sum += (int)($byType[$type] ?? 0);
        }

        return $sum;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function countReasonContains(array $rows, string $needle): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $reason = (string)($row['reason'] ?? '');
            if ($reason !== '' && \str_contains($reason, $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function countUnknownQueryProbes(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $uri = (string)($row['uri'] ?? '');
            if ($uri === '' || !\str_contains($uri, '?')) {
                continue;
            }
            $query = \strtolower((string)(\parse_url($uri, PHP_URL_QUERY) ?? ''));
            if ($query === '') {
                $parts = \explode('?', $uri, 2);
                $query = \strtolower((string)($parts[1] ?? ''));
            }
            if ($query === '') {
                continue;
            }
            if ($this->queryLooksLikeScan($query)) {
                $n++;
            }
        }

        return $n;
    }

    private function queryLooksLikeScan(string $query): bool
    {
        foreach (self::SCAN_QUERY_MARKERS as $marker) {
            if (\str_contains($query, $marker)) {
                return true;
            }
        }
        foreach (\explode('&', $query) as $pair) {
            $key = \strtolower((string)(\explode('=', $pair, 2)[0] ?? ''));
            if ($key === '') {
                continue;
            }
            if (\preg_match('/^(?:x{5,}|test\\d{2,}|q\\d{4,}|param\\d+|asdf+|zzz+)$/', $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:string,severity:string,code:string,title:string,message:string,count:int}
     */
    private function warning(
        string $id,
        string $severity,
        string $code,
        string $title,
        string $message,
        int $count,
    ): array {
        return [
            'id' => $id,
            'severity' => $severity,
            'code' => $code,
            'title' => $title,
            'message' => $message,
            'count' => $count,
        ];
    }
}
