<?php
declare(strict_types=1);

/**
 * 通过各适配器 getDnsRecords 返回的记录：仅取与 FQDN 匹配的 A/AAAA 的 value（内容），
 * 与源站 IPv4/IPv6 做字符串比对。不跟 CNAME、不公网递归。
 */

namespace Weline\Websites\Service;

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
        if ($adapter === null) {
            return \array_merge($base, ['error' => __('未找到域名商适配器')]);
        }
        try {
            $r = $adapter->checkFqdnOriginPointsToServer(
                $fqdn,
                $zoneRoot,
                $account->getCredentials(),
                $serverIpv4,
                $serverIpv6,
            );
        } catch (\Throwable $e) {
            return \array_merge($base, [
                'via_provider' => true,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'matches' => (bool) ($r['matches'] ?? false),
            'api_ok' => (bool) ($r['api_ok'] ?? false),
            'has_direct_records' => (bool) ($r['has_direct_records'] ?? false),
            'origin_ipv4' => (string) ($r['origin_ipv4'] ?? ''),
            'origin_ipv6' => (string) ($r['origin_ipv6'] ?? ''),
            'via_provider' => true,
            'error' => (string) ($r['error'] ?? ''),
        ];
    }
}
