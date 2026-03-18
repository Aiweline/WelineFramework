<?php
declare(strict_types=1);

/**
 * 默认实现：listZoneDnsRecordsForAccount 拉全 zone 后，按通用 host→FQDN 规则筛 A/AAAA 与源站 IP 比对。
 * 供应商若 host 形态特殊（如仅返回相对名、或 FQDN 带尾点），应在本适配器中覆盖 checkFqdnOriginPointsToServer。
 */

namespace Weline\Websites\Adapter\Concern;

trait DefaultDnsZoneOriginMatchTrait
{
    public function checkFqdnOriginPointsToServer(
        string $fqdn,
        string $zoneRoot,
        array $credentials,
        string $serverIpv4,
        string $serverIpv6,
    ): array {
        $base = [
            'matches' => false,
            'api_ok' => false,
            'has_direct_records' => false,
            'origin_ipv4' => '',
            'origin_ipv6' => '',
            'error' => '',
        ];
        $fqdn = \strtolower(\trim($fqdn));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($fqdn === '' || $zoneRoot === '') {
            return $base;
        }
        if (!\str_ends_with($fqdn, $zoneRoot) && $fqdn !== $zoneRoot) {
            return \array_merge($base, ['error' => __('FQDN 与 Zone 根域不匹配')]);
        }

        try {
            $records = $this->listZoneDnsRecordsForAccount($zoneRoot, $credentials);
        } catch (\Throwable $e) {
            return \array_merge($base, ['error' => $e->getMessage()]);
        }

        $direct = self::defaultCollectDirectAaaaForFqdn($fqdn, $zoneRoot, $records);
        $has = $direct['ipv4'] !== [] || $direct['ipv6'] !== [];
        if (!$has) {
            return [
                'matches' => false,
                'api_ok' => true,
                'has_direct_records' => false,
                'origin_ipv4' => '',
                'origin_ipv6' => '',
                'error' => '',
            ];
        }

        $originV4 = \implode(',', $direct['ipv4']);
        $originV6 = \implode(',', $direct['ipv6']);
        $matchV4 = $serverIpv4 !== '' && \in_array($serverIpv4, $direct['ipv4'], true);
        $matchV6 = $serverIpv6 !== '' && \in_array(\strtolower($serverIpv6), $direct['ipv6'], true);

        return [
            'matches' => $matchV4 || $matchV6,
            'api_ok' => true,
            'has_direct_records' => true,
            'origin_ipv4' => $originV4,
            'origin_ipv6' => $originV6,
            'error' => '',
        ];
    }

    /**
     * @param list<array{type?: string, host?: string, value?: string}> $records
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    private static function defaultCollectDirectAaaaForFqdn(string $fqdn, string $zoneRoot, array $records): array
    {
        $ipv4 = [];
        $ipv6 = [];
        foreach ($records as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $nameFqdn = self::defaultRecordHostToFqdn((string) ($r['host'] ?? '@'), $zoneRoot);
            if ($nameFqdn !== $fqdn) {
                continue;
            }
            $type = \strtoupper((string) ($r['type'] ?? ''));
            $val = \trim((string) ($r['value'] ?? ''));
            if ($type === 'A' && $val !== '') {
                $ipv4[] = $val;
            }
            if ($type === 'AAAA' && $val !== '') {
                $ipv6[] = \strtolower($val);
            }
        }

        return [
            'ipv4' => \array_values(\array_unique($ipv4)),
            'ipv6' => \array_values(\array_unique($ipv6)),
        ];
    }

    private static function defaultRecordHostToFqdn(string $host, string $zoneRoot): string
    {
        $host = \strtolower(\trim($host, '.'));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($host === '' || $host === '@') {
            return $zoneRoot;
        }
        if ($host === $zoneRoot) {
            return $zoneRoot;
        }
        if (\str_ends_with($host, '.' . $zoneRoot)) {
            return $host;
        }

        return $host . '.' . $zoneRoot;
    }
}
