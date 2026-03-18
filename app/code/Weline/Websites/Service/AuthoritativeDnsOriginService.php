<?php
declare(strict_types=1);

/**
 * 通过各适配器 getDnsRecords 返回的记录：仅取与 FQDN 匹配的 A/AAAA 的 value（内容），
 * 与源站 IPv4/IPv6 做字符串比对。不跟 CNAME、不公网递归。
 */

namespace Weline\Websites\Service;

use Weline\Websites\Api\DnsCdnZoneRecordsProviderInterface;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

class AuthoritativeDnsOriginService
{
    public function __construct(
        private DomainRegistrarResolverService $resolver,
        private DomainRegistrarAccount $accountModel,
        private DomainRegistrar $registrarModel,
    ) {
    }

    /**
     * @return array{
     *     matches: bool,
     *     api_ok: bool,
     *     has_direct_records: bool,
     *     origin_ipv4: string,
     *     origin_ipv6: string,
     *     via_provider: bool,
     *     error: string
     * }
     */
    public function originPointsToServer(
        string $fqdn,
        string $zoneRoot,
        int $dnsAccountId,
        int $cdnAccountId,
        string $serverIpv4,
        string $serverIpv6,
    ): array {
        $accountId = $dnsAccountId > 0 ? $dnsAccountId : $cdnAccountId;

        return $this->originPointsToServerForAccount($fqdn, $zoneRoot, $accountId, $serverIpv4, $serverIpv6);
    }

    /**
     * @return array{
     *     matches: bool,
     *     api_ok: bool,
     *     has_direct_records: bool,
     *     origin_ipv4: string,
     *     origin_ipv6: string,
     *     via_provider: bool,
     *     error: string
     * }
     */
    public function originPointsToServerForAccount(
        string $fqdn,
        string $zoneRoot,
        int $accountId,
        string $serverIpv4,
        string $serverIpv6,
    ): array {
        $base = [
            'matches' => false,
            'api_ok' => false,
            'has_direct_records' => false,
            'origin_ipv4' => '',
            'origin_ipv6' => '',
            'via_provider' => false,
            'error' => '',
        ];
        if ($accountId <= 0) {
            return $base;
        }
        $fqdn = \strtolower(\trim($fqdn));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($fqdn === '' || $zoneRoot === '') {
            return $base;
        }
        if (!\str_ends_with($fqdn, $zoneRoot) && $fqdn !== $zoneRoot) {
            return \array_merge($base, ['error' => __('FQDN 与 Zone 根域不匹配')]);
        }

        $account = clone $this->accountModel;
        $account->load($accountId);
        if ($account->getAccountId() <= 0) {
            return \array_merge($base, ['error' => __('账户不存在')]);
        }

        $registrar = clone $this->registrarModel;
        $registrar->load($account->getRegistrarId());
        $adapter = $this->resolver->getAdapter($registrar->getCode());
        if (!$adapter instanceof DnsCdnZoneRecordsProviderInterface) {
            return \array_merge($base, ['error' => __('适配器未实现 DNS/CDN 账户 zone 记录查询')]);
        }

        try {
            $records = $adapter->listZoneDnsRecordsForAccount($zoneRoot, $account->getCredentials());
        } catch (\Throwable $e) {
            return \array_merge($base, [
                'via_provider' => true,
                'error' => $e->getMessage(),
            ]);
        }

        $direct = $this->collectDirectAaaaContentsForFqdn($fqdn, $zoneRoot, $records);
        $has = $direct['ipv4'] !== [] || $direct['ipv6'] !== [];
        $originV4 = \implode(',', $direct['ipv4']);
        $originV6 = \implode(',', $direct['ipv6']);

        if (!$has) {
            return [
                'matches' => false,
                'api_ok' => true,
                'has_direct_records' => false,
                'origin_ipv4' => '',
                'origin_ipv6' => '',
                'via_provider' => true,
                'error' => '',
            ];
        }

        $matchV4 = $serverIpv4 !== '' && \in_array($serverIpv4, $direct['ipv4'], true);
        $matchV6 = $serverIpv6 !== '' && \in_array(\strtolower($serverIpv6), $direct['ipv6'], true);

        return [
            'matches' => $matchV4 || $matchV6,
            'api_ok' => true,
            'has_direct_records' => true,
            'origin_ipv4' => $originV4,
            'origin_ipv6' => $originV6,
            'via_provider' => true,
            'error' => '',
        ];
    }

    /**
     * 仅本 FQDN 上的 A/AAAA，取适配器返回的 value 原文（去空格），不做 CNAME 跳转。
     *
     * @param array<int, array{type?: string, host?: string, value?: string}> $records
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    private function collectDirectAaaaContentsForFqdn(string $fqdn, string $zoneRoot, array $records): array
    {
        $ipv4 = [];
        $ipv6 = [];
        foreach ($records as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $nameFqdn = $this->recordHostToFqdn((string) ($r['host'] ?? '@'), $zoneRoot);
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

    private function recordHostToFqdn(string $host, string $zoneRoot): string
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
