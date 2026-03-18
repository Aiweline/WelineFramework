<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池解析服务
 *
 * 负责检测 DomainPool 中域名的解析状态、执行解析检测、更新建站就绪状态
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;

class DomainPoolResolveService
{
    public function __construct(
        private ServerIpService $serverIpService,
        private AuthoritativeDnsOriginService $authoritativeDnsOriginService,
        private Domain $domainModel,
    ) {
    }

    /**
     * 检测单个域名池域名的解析状态
     *
     * @param DomainPool $poolDomain 域名池模型
     * @return array{resolved: bool, ipv4: string, ipv6: string, is_local: bool, site_ready: bool, error: string, resolve_off_local?: bool}
     *   resolve_off_local 为 true 时表示：上次指向本站，本次不再指向
     */
    public function checkResolve(DomainPool $poolDomain): array
    {
        $domainName = $poolDomain->getDomain();
        $now = \date('Y-m-d H:i:s');

        $wasLocalBefore = (bool) ($poolDomain->getData(DomainPool::schema_fields_IS_LOCAL_SERVER) ?? false);

        // 解析开始时设置“解析中”状态
        $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVING);
        $poolDomain->save();

        $ipv4 = '';
        $ipv6 = '';
        $error = '';

        try {
            $ipv4 = $this->resolveA($domainName);
        } catch (\Throwable $e) {
            $error .= 'A: ' . $e->getMessage() . '; ';
        }

        try {
            $ipv6 = $this->resolveAAAA($domainName);
        } catch (\Throwable $e) {
            // IPv6 失败不算错误，很多域名没有 AAAA 记录
        }

        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();

        $publicIsLocal = false;
        if ($ipv4 !== '' && $serverIpv4 !== '' && $ipv4 === $serverIpv4) {
            $publicIsLocal = true;
        }
        if (!$publicIsLocal && $ipv6 !== '' && $serverIpv6 !== '' && \strtolower($ipv6) === \strtolower($serverIpv6)) {
            $publicIsLocal = true;
        }

        $resolved = $ipv4 !== '' || $ipv6 !== '';
        $localDecision = $this->resolveIsLocalFromDnsRecordContent($poolDomain, $publicIsLocal, $serverIpv4, $serverIpv6);
        $isLocal = $localDecision['is_local'];
        $isLocalViaAuthoritative = $localDecision['via_authoritative'];

        if (!$resolved && $error === '') {
            $error = __('未解析到有效 IP，请检查域名是否已添加 A 或 AAAA 记录');
        }
        $resolveStatus = $resolved ? DomainPool::RESOLVE_STATUS_RESOLVED : DomainPool::RESOLVE_STATUS_ERROR;

        if ($error !== '' && !$resolved) {
            $resolveStatus = DomainPool::RESOLVE_STATUS_ERROR;
        }

        // 更新域名池模型
        $poolDomain->setResolvedIp($ipv4);
        $poolDomain->setResolvedIpv6($ipv6);
        $poolDomain->setIsLocalServer($isLocal);
        $poolDomain->setResolveStatus($resolveStatus);
        $poolDomain->setDnsStatus($resolved ? DomainPool::INFRA_STATUS_READY : DomainPool::INFRA_STATUS_ERROR);
        $poolDomain->setCdnStatus($resolved ? DomainPool::INFRA_STATUS_READY : DomainPool::INFRA_STATUS_ERROR);
        $poolDomain->setResolveCheckedAt($now);
        $poolDomain->setResolveError(\trim($error, '; '));
        
        // 计算并更新建站就绪状态
        $siteReady = $poolDomain->calculateSiteReady();
        
        $poolDomain->save();

        $resolveOffLocal = $wasLocalBefore && !$isLocal;

        return $this->buildCheckResult($resolved, $ipv4, $ipv6, $isLocal, $siteReady, $error, $resolveOffLocal, $serverIpv4, $serverIpv6, $isLocalViaAuthoritative);
    }

    /**
     * 检测并仅在 IP/状态变更时更新域名池记录（避免无变化的冗余保存）
     *
     * @param DomainPool $poolDomain 域名池模型
     * @return array{resolved: bool, ipv4: string, ipv6: string, is_local: bool, site_ready: bool, error: string, updated: bool}
     */
    public function checkAndUpdateIfChanged(DomainPool $poolDomain): array
    {
        $domainName = $poolDomain->getDomain();
        $now = \date('Y-m-d H:i:s');

        $oldIpv4 = (string) ($poolDomain->getData(DomainPool::schema_fields_RESOLVED_IP) ?? '');
        $oldIpv6 = (string) ($poolDomain->getData(DomainPool::schema_fields_RESOLVED_IPV6) ?? '');
        $oldIsLocal = (bool) ($poolDomain->getData(DomainPool::schema_fields_IS_LOCAL_SERVER) ?? false);
        $oldStatus = (string) ($poolDomain->getData(DomainPool::schema_fields_RESOLVE_STATUS) ?? '');

        $poolDomain->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVING);
        $poolDomain->save();

        $ipv4 = '';
        $ipv6 = '';
        $error = '';

        try {
            $ipv4 = $this->resolveA($domainName);
        } catch (\Throwable $e) {
            $error .= 'A: ' . $e->getMessage() . '; ';
        }

        try {
            $ipv6 = $this->resolveAAAA($domainName);
        } catch (\Throwable $e) {
            // IPv6 失败不视为错误
        }

        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();

        $publicIsLocal = false;
        if ($ipv4 !== '' && $serverIpv4 !== '' && $ipv4 === $serverIpv4) {
            $publicIsLocal = true;
        }
        if (!$publicIsLocal && $ipv6 !== '' && $serverIpv6 !== '' && \strtolower($ipv6) === \strtolower($serverIpv6)) {
            $publicIsLocal = true;
        }

        $resolved = $ipv4 !== '' || $ipv6 !== '';
        $isLocal = $this->resolveIsLocalFromDnsRecordContent($poolDomain, $publicIsLocal, $serverIpv4, $serverIpv6)['is_local'];

        if (!$resolved && $error === '') {
            $error = __('未解析到有效 IP，请检查域名是否已添加 A 或 AAAA 记录');
        }
        $resolveStatus = $resolved ? DomainPool::RESOLVE_STATUS_RESOLVED : DomainPool::RESOLVE_STATUS_ERROR;
        if ($error !== '' && !$resolved) {
            $resolveStatus = DomainPool::RESOLVE_STATUS_ERROR;
        }

        $changed = $oldIpv4 !== $ipv4 || $oldIpv6 !== $ipv6 || $oldIsLocal !== $isLocal || $oldStatus !== $resolveStatus;

        if ($changed) {
            $poolDomain->setResolvedIp($ipv4);
            $poolDomain->setResolvedIpv6($ipv6);
            $poolDomain->setIsLocalServer($isLocal);
            $poolDomain->setResolveStatus($resolveStatus);
            $poolDomain->setDnsStatus($resolved ? DomainPool::INFRA_STATUS_READY : DomainPool::INFRA_STATUS_ERROR);
            $poolDomain->setCdnStatus($resolved ? DomainPool::INFRA_STATUS_READY : DomainPool::INFRA_STATUS_ERROR);
            $poolDomain->setResolveCheckedAt($now);
            $poolDomain->setResolveError(\trim($error, '; '));
            $poolDomain->calculateSiteReady();
            $poolDomain->save();
        }

        $result = $this->buildCheckResult($resolved, $ipv4, $ipv6, $isLocal, $poolDomain->getData(DomainPool::schema_fields_SITE_READY) ?: false, \trim($error, '; '), false, $serverIpv4, $serverIpv6);
        $result['updated'] = $changed;
        return $result;
    }

    private function buildCheckResult(
        bool $resolved,
        string $ipv4,
        string $ipv6,
        bool $isLocal,
        $siteReady,
        string $error,
        bool $resolveOffLocal,
        string $serverIpv4 = '',
        string $serverIpv6 = '',
        bool $isLocalViaAuthoritative = false,
    ): array {
        $result = [
            'resolved' => $resolved,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'is_local' => $isLocal,
            'is_local_server' => $isLocal,
            'site_ready' => (bool) $siteReady,
            'error' => $error,
        ];
        if ($resolveOffLocal) {
            $result['resolve_off_local'] = true;
        }
        if ($isLocalViaAuthoritative) {
            $result['is_local_via_authoritative'] = true;
        }
        if ($serverIpv4 !== '' || $serverIpv6 !== '') {
            $result['server_ipv4'] = $serverIpv4;
            $result['server_ipv6'] = $serverIpv6;
        }

        return $result;
    }

    /**
     * 有 DNS/CDN 账户且 API 能列出记录时：仅当该 FQDN 存在 A/AAAA 则用记录 value 与源站 IP 判定；否则回退公网解析结果。
     *
     * @return array{is_local: bool, via_authoritative: bool}
     */
    private function resolveIsLocalFromDnsRecordContent(
        DomainPool $poolDomain,
        bool $publicIsLocal,
        string $serverIpv4,
        string $serverIpv6,
    ): array {
        $parent = $this->loadParentDomainForPool($poolDomain);
        if ($parent === null) {
            return ['is_local' => $publicIsLocal, 'via_authoritative' => false];
        }
        $dnsId = (int) $parent->getDnsAccountId();
        $cdnId = (int) $parent->getCdnAccountId();
        if ($dnsId <= 0 && $cdnId <= 0) {
            return ['is_local' => $publicIsLocal, 'via_authoritative' => false];
        }
        $zoneRoot = \trim($poolDomain->getRootDomain());
        if ($zoneRoot === '') {
            $zoneRoot = \trim($parent->getDomain());
        }
        $auth = $this->authoritativeDnsOriginService->originPointsToServer(
            \trim($poolDomain->getDomain()),
            $zoneRoot,
            $dnsId,
            $cdnId,
            $serverIpv4,
            $serverIpv6,
        );
        if ($auth['api_ok'] && $auth['has_direct_records']) {
            return ['is_local' => (bool) $auth['matches'], 'via_authoritative' => true];
        }

        return ['is_local' => $publicIsLocal, 'via_authoritative' => false];
    }

    private function loadParentDomainForPool(DomainPool $poolDomain): ?Domain
    {
        $pid = $poolDomain->getParentDomainId();
        if ($pid > 0) {
            $d = clone $this->domainModel;
            $d->load($pid);
            if ($d->getDomainId() > 0) {
                return $d;
            }
        }
        $root = \trim($poolDomain->getRootDomain());
        if ($root !== '') {
            $d = clone $this->domainModel;
            $d->clearQuery()
                ->where(Domain::schema_fields_DOMAIN, $root)
                ->find()
                ->fetch();
            if ($d->getDomainId() > 0) {
                return $d;
            }
        }

        return null;
    }

    /**
     * 批量检测域名池解析状态
     *
     * @param array $poolIds 域名池 ID 数组
     * @return array{checked: int, resolved: int, local: int, ready: int, errors: int}
     */
    public function batchCheckResolve(array $poolIds): array
    {
        $checked = 0;
        $resolved = 0;
        $local = 0;
        $ready = 0;
        $errors = 0;

        $poolModel = ObjectManager::getInstance(DomainPool::class);
        
        foreach ($poolIds as $poolId) {
            $domain = ObjectManager::getInstance(DomainPool::class, [], false);
            $domain->loadByPoolId((int) $poolId);
            
            if (!$domain->getPoolId()) {
                continue;
            }

            $result = $this->checkResolve($domain);
            $checked++;

            if ($result['resolved']) {
                $resolved++;
            } else {
                $errors++;
            }

            if ($result['is_local']) {
                $local++;
            }
            
            if ($result['site_ready']) {
                $ready++;
            }
        }

        return [
            'checked' => $checked,
            'resolved' => $resolved,
            'local' => $local,
            'ready' => $ready,
            'errors' => $errors,
        ];
    }

    /**
     * DNS A 记录查询
     */
    private function resolveA(string $domain): string
    {
        $records = @\dns_get_record($domain, DNS_A);

        if ($records === false || $records === []) {
            return '';
        }

        return $records[0]['ip'] ?? '';
    }

    /**
     * DNS AAAA 记录查询
     */
    private function resolveAAAA(string $domain): string
    {
        $records = @\dns_get_record($domain, DNS_AAAA);

        if ($records === false || $records === []) {
            return '';
        }

        return $records[0]['ipv6'] ?? '';
    }
}
