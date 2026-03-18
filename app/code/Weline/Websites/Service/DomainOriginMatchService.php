<?php
declare(strict_types=1);

/**
 * 域名是否指向本机源站 —— 唯一入口
 *
 * 规则：有 DNS/CDN 账户且 API 能列出该 FQDN 的 A/AAAA 时以权威记录内容为准；
 * 否则以公网递归得到的全部 A/AAAA 记录内容与本机公网 IP 比对（CDN 边缘 IP 不参与此判定）。
 */
namespace Weline\Websites\Service;

final class DomainOriginMatchService
{
    public function __construct(
        private ServerIpService $serverIpService,
        private AuthoritativeDnsOriginService $authoritativeDnsOriginService,
    ) {
    }

    /**
     * FQDN 是否指向本机（权威优先，否则公网解析记录内容）
     */
    public function fqdnPointsToServer(string $fqdn, string $zoneRoot, int $dnsAccountId, int $cdnAccountId): bool
    {
        return $this->fqdnPointsToServerDecision($fqdn, $zoneRoot, $dnsAccountId, $cdnAccountId)['points_to_server'];
    }

    /**
     * @return array{points_to_server: bool, via_authoritative: bool}
     */
    public function fqdnPointsToServerDecision(
        string $fqdn,
        string $zoneRoot,
        int $dnsAccountId,
        int $cdnAccountId,
    ): array {
        $fqdn = \strtolower(\trim($fqdn));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($fqdn === '') {
            return ['points_to_server' => false, 'via_authoritative' => false];
        }
        if ($zoneRoot === '') {
            $zoneRoot = $fqdn;
        }
        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();

        if ($dnsAccountId > 0 || $cdnAccountId > 0) {
            $auth = $this->authoritativeDnsOriginService->originPointsToServer(
                $fqdn,
                $zoneRoot,
                $dnsAccountId,
                $cdnAccountId,
                $serverIpv4,
                $serverIpv6,
            );
            if ($auth['api_ok'] && $auth['has_direct_records']) {
                return [
                    'points_to_server' => (bool) $auth['matches'],
                    'via_authoritative' => true,
                ];
            }
        }

        return [
            'points_to_server' => $this->publicAaaaRecordContentMatchesServer($fqdn, $serverIpv4, $serverIpv6),
            'via_authoritative' => false,
        ];
    }

    /**
     * 用「注册商/任务账户」查权威区记录（与根域绑定的 DNS/CDN 账户不同场景）
     */
    public function fqdnPointsToServerForRegistrarAccount(
        string $fqdn,
        string $zoneRoot,
        int $registrarAccountId,
    ): bool {
        $fqdn = \strtolower(\trim($fqdn));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($fqdn === '') {
            return false;
        }
        if ($zoneRoot === '') {
            $zoneRoot = $fqdn;
        }
        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();
        if ($registrarAccountId > 0) {
            $auth = $this->authoritativeDnsOriginService->originPointsToServerForAccount(
                $fqdn,
                $zoneRoot,
                $registrarAccountId,
                $serverIpv4,
                $serverIpv6,
            );
            if ($auth['api_ok'] && $auth['has_direct_records']) {
                return (bool) $auth['matches'];
            }
        }
        return $this->publicAaaaRecordContentMatchesServer($fqdn, $serverIpv4, $serverIpv6);
    }

    /**
     * 仅公网解析：全部 A/AAAA 记录内容是否含本机 IP
     */
    public function publicAaaaRecordContentMatchesServer(string $fqdn, string $serverIpv4, string $serverIpv6): bool
    {
        $ips = $this->collectPublicAaaaRecordIps($fqdn);
        if ($serverIpv4 !== '' && \in_array($serverIpv4, $ips['ipv4'], true)) {
            return true;
        }
        if ($serverIpv6 !== '') {
            $n = \strtolower($serverIpv6);
            foreach ($ips['ipv6'] as $v) {
                if (\strtolower(\trim($v)) === $n) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    public function collectPublicAaaaRecordIps(string $fqdn): array
    {
        $ipv4 = [];
        $ipv6 = [];
        $fqdn = \trim($fqdn);
        if ($fqdn === '') {
            return ['ipv4' => [], 'ipv6' => []];
        }
        try {
            $aRecords = @\dns_get_record($fqdn, \DNS_A);
            if (\is_array($aRecords)) {
                foreach ($aRecords as $r) {
                    $ip = \trim((string) ($r['ip'] ?? ''));
                    if ($ip !== '') {
                        $ipv4[] = $ip;
                    }
                }
            }
        } catch (\Throwable) {
        }
        try {
            $aaaaRecords = @\dns_get_record($fqdn, \DNS_AAAA);
            if (\is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $r) {
                    $ip = \trim((string) ($r['ipv6'] ?? ''));
                    if ($ip !== '') {
                        $ipv6[] = $ip;
                    }
                }
            }
        } catch (\Throwable) {
        }
        return [
            'ipv4' => \array_values(\array_unique($ipv4)),
            'ipv6' => \array_values(\array_unique($ipv6)),
        ];
    }

    /**
     * 单条 A/AAAA 记录值是否为本机源站 IP（同步单条记录时用）
     */
    public function recordIpValueIsOrigin(string $recordType, string $value): bool
    {
        $t = \strtoupper(\trim($recordType));
        if ($t !== 'A' && $t !== 'AAAA') {
            return false;
        }
        return $this->serverIpService->isLocalServer(\trim($value));
    }
}
