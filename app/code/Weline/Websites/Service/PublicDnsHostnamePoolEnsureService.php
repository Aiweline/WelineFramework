<?php
declare(strict_types=1);

/**
 * 公网 DNS 已通主机名 → 域名池注册 +（可选）DNS-01 证书。
 *
 * 机制：凡权威 DNS 已为某主机名写好解析（邮件 A/CNAME、建站子域等），
 * 不得停在 CDN/DNS API 侧；须确保：
 * 1) 根域 Domain 落库并绑定 DNS/CDN 服务商账户（UI 服务商列有值）
 * 2) DomainPool 入池并标 resolved + origin_ready
 * 3) 立即申请 HTTPS（不依赖 cron；生产可能未装 cron）
 *
 * owning_module: Weline_Websites
 * not_to_do: 不把公网主机当本地域名走 local_domain_registered；不在 Mail/Cdn 硬编码池表。
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPoolFlowLog;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

final class PublicDnsHostnamePoolEnsureService
{
    public function __construct(
        private readonly Domain $domainModel,
        private readonly DomainPool $poolModel,
        private readonly DomainRegistrar $registrarModel,
        private readonly DomainRegistrarAccount $accountModel,
        private readonly DomainRegistrarResolverService $resolver,
        private readonly CertificateRequestService $certificateRequest,
        private readonly DomainPoolLifecycleService $lifecycle,
        private readonly DomainPoolFlowLogService $flowLog,
        private readonly DomainParserService $parser,
    ) {
    }

    /**
     * @param array{
     *   root_domain?: string,
     *   hostnames?: list<string>|string,
     *   origin_ip?: string,
     *   dns_provider?: string,
     *   cdn_account_id?: int,
     *   request_certificate?: bool,
     *   source?: string,
     * } $input
     * @return array{
     *   success: bool,
     *   message: string,
     *   root_domain: string,
     *   domain_id: int,
     *   dns_account_id: int,
     *   cdn_account_id: int,
     *   hostnames: list<array<string, mixed>>,
     * }
     */
    public function ensure(array $input): array
    {
        $root = $this->normalizeHostname((string) ($input['root_domain'] ?? ''));
        $hostnames = $this->normalizeHostnameList($input['hostnames'] ?? []);
        $originIp = \trim((string) ($input['origin_ip'] ?? ''));
        $dnsProvider = \strtolower(\trim((string) ($input['dns_provider'] ?? 'cloudflare'))) ?: 'cloudflare';
        $cdnAccountId = max(0, (int) ($input['cdn_account_id'] ?? 0));
        $requestCert = (bool) ($input['request_certificate'] ?? true);
        $source = \trim((string) ($input['source'] ?? 'public_dns')) ?: 'public_dns';

        if ($root === '') {
            if ($hostnames !== []) {
                $root = $this->parser->parseRootDomain($hostnames[0]);
            }
        }
        $root = $this->normalizeHostname($root);
        if ($root === '') {
            return $this->fail(__('根域名不能为空'), $root, 0, 0, $cdnAccountId, []);
        }

        if ($hostnames === []) {
            return $this->fail(__('主机名列表不能为空'), $root, 0, 0, $cdnAccountId, []);
        }

        foreach ($hostnames as $hn) {
            if ($hn !== $root && !\str_ends_with($hn, '.' . $root)) {
                return $this->fail(
                    __('主机名 %{1} 不属于根域 %{2}', [$hn, $root]),
                    $root,
                    0,
                    0,
                    $cdnAccountId,
                    []
                );
            }
        }

        try {
            $dnsAccountId = $this->ensureDnsRegistrarAccount($dnsProvider, $cdnAccountId);
            if ($dnsAccountId <= 0) {
                return $this->fail(
                    (string) __('无法绑定 DNS 服务商账户（%{1}）。请在 CDN/域名商中配置可用凭据。', [$dnsProvider]),
                    $root,
                    0,
                    0,
                    $cdnAccountId,
                    []
                );
            }
            if ($cdnAccountId <= 0 && $dnsProvider === 'cloudflare') {
                $cdnAccountId = $this->resolveDefaultCloudflareCdnAccountId();
            }

            $rootDomain = $this->ensureRootDomain($root, $dnsProvider, $dnsAccountId, $cdnAccountId);
            $domainId = (int) $rootDomain->getDomainId();
            if ($domainId <= 0) {
                return $this->fail(__('根域保存失败'), $root, 0, $dnsAccountId, $cdnAccountId, []);
            }

            $this->relinkOrphanPools($root, $domainId, $dnsProvider);

            $hostResults = [];
            foreach ($hostnames as $hostname) {
                $hostResults[] = $this->ensurePoolHostname(
                    $hostname,
                    $root,
                    $domainId,
                    $dnsProvider,
                    $originIp,
                    $requestCert,
                    $source,
                );
            }

            $failed = [];
            foreach ($hostResults as $row) {
                if (($row['success'] ?? false) !== true) {
                    $failed[] = (string) ($row['hostname'] ?? '') . ': ' . (string) ($row['message'] ?? '');
                }
            }

            return [
                'success' => $failed === [],
                'message' => $failed === []
                    ? (string) __('公网 DNS 主机已入域名池%{1}', [$requestCert ? __('并已申请证书') : ''])
                    : (string) __('部分主机入池/证书失败：%{1}', [\implode('; ', $failed)]),
                'root_domain' => $root,
                'domain_id' => $domainId,
                'dns_account_id' => $dnsAccountId,
                'cdn_account_id' => $cdnAccountId,
                'hostnames' => $hostResults,
            ];
        } catch (\Throwable $e) {
            return $this->fail(
                __('公网 DNS 入池失败：%{1}', [$e->getMessage()]),
                $root,
                0,
                0,
                $cdnAccountId,
                []
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $hostnames
     * @return array{success:bool,message:string,root_domain:string,domain_id:int,dns_account_id:int,cdn_account_id:int,hostnames:list}
     */
    private function fail(
        string $message,
        string $root,
        int $domainId,
        int $dnsAccountId,
        int $cdnAccountId,
        array $hostnames,
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'root_domain' => $root,
            'domain_id' => $domainId,
            'dns_account_id' => $dnsAccountId,
            'cdn_account_id' => $cdnAccountId,
            'hostnames' => $hostnames,
        ];
    }

    private function ensureDnsRegistrarAccount(string $dnsProvider, int $preferredCdnAccountId): int
    {
        if ($dnsProvider !== 'cloudflare') {
            $existing = ObjectManager::getInstance(DomainResolveService::class)
                ->findAccountByProviderCode($dnsProvider);

            return $existing !== null ? max(0, $existing->getAccountId()) : 0;
        }

        // Cloudflare：每次从 CDN 账户刷新桥接凭据（auth_mode/Global Key），禁止早退只留 Token
        $creds = $this->resolveCloudflareBridgeCredentials($preferredCdnAccountId);
        if ($creds === null) {
            $existing = ObjectManager::getInstance(DomainResolveService::class)
                ->findAccountByProviderCode($dnsProvider);

            return $existing !== null ? max(0, $existing->getAccountId()) : 0;
        }

        $adapter = $this->resolver->getAdapter('cloudflare');
        if ($adapter === null) {
            return 0;
        }

        $reg = clone $this->registrarModel;
        $reg->clearQuery()
            ->where(DomainRegistrar::schema_fields_CODE, 'cloudflare')
            ->find()
            ->fetch();
        if ((int) $reg->getData(DomainRegistrar::schema_fields_ID) <= 0) {
            $reg->clearData();
            $reg->setData(DomainRegistrar::schema_fields_CODE, 'cloudflare');
            $reg->setData(DomainRegistrar::schema_fields_NAME, $adapter->getRegistrarName());
            $reg->setData(DomainRegistrar::schema_fields_DESCRIPTION, $adapter->getDescription());
            $reg->setData(DomainRegistrar::schema_fields_STATUS, DomainRegistrar::STATUS_ACTIVE);
            $reg->save();
        }
        $registrarId = (int) $reg->getData(DomainRegistrar::schema_fields_ID);
        if ($registrarId <= 0) {
            return 0;
        }

        $token = (string) ($creds['api_token'] ?? '');
        $email = (string) ($creds['email'] ?? '');
        $globalKey = (string) ($creds['api_key'] ?? '');

        // 复用或刷新已有 CDN bridge 账户（同 registrar + 名称）
        $rows = (clone $this->accountModel)->clearQuery()
            ->where(DomainRegistrarAccount::schema_fields_REGISTRAR_ID, $registrarId)
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        $acc = null;
        foreach ($rows as $row) {
            $aid = (int) ($row[DomainRegistrarAccount::schema_fields_ID] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $probe = clone $this->accountModel;
            $probe->clearQuery()->load($aid);
            if ($probe->getAccountId() <= 0) {
                continue;
            }
            $name = \strtolower(\trim($probe->getAccountName()));
            $extra = $probe->getExtraConfig();
            $bridged = ($extra['bridged_from'] ?? '') === 'cdn' || \str_contains($name, 'cdn bridge');
            if ($bridged) {
                $acc = $probe;
                break;
            }
        }
        if ($acc === null) {
            $acc = clone $this->accountModel;
            $acc->clearData();
            $acc->setRegistrarId($registrarId);
            $acc->setAccountName('Cloudflare (CDN bridge)');
        }

        $extra = [
            'bridged_from' => 'cdn',
            'cdn_account_id' => (int) ($creds['cdn_account_id'] ?? 0),
        ];
        $authMode = \strtolower(\trim((string) ($creds['auth_mode'] ?? '')));
        if ($authMode === 'global' || $authMode === 'token') {
            $extra['auth_mode'] = $authMode;
        }
        // Global Key + Token 一并写入，Registrar 双鉴权与 CDN 一致
        if ($globalKey !== '' && $email !== '') {
            $acc->setApiKey($globalKey);
            $extra['email'] = $email;
            if (!isset($extra['auth_mode'])) {
                $extra['auth_mode'] = 'global';
            }
        }
        if ($token !== '') {
            $acc->setApiSecret($token);
        }
        $acc->setStatus(DomainRegistrarAccount::STATUS_ACTIVE);
        $acc->setExtraConfig($extra);
        $acc->save();

        return max(0, $acc->getAccountId());
    }

    /**
     * CDN Query `getAccount` 故意不返回明文 credentials；桥接须经 Cdn AccountManager 本模块读取。
     *
     * @return array{api_token?: string, email?: string, api_key?: string, cdn_account_id: int}|null
     */
    private function resolveCloudflareBridgeCredentials(int $preferredCdnAccountId): ?array
    {
        if (!\class_exists(\Weline\Cdn\Service\AccountManager::class)
            || !\class_exists(\Weline\Cdn\Model\Account::class)
        ) {
            return null;
        }

        try {
            /** @var \Weline\Cdn\Service\AccountManager $manager */
            $manager = ObjectManager::getInstance(\Weline\Cdn\Service\AccountManager::class);
        } catch (\Throwable) {
            return null;
        }

        $candidates = [];
        if ($preferredCdnAccountId > 0) {
            $candidates[] = $preferredCdnAccountId;
        }
        try {
            $default = $manager->getDefaultAccount('cloudflare');
            if ($default instanceof \Weline\Cdn\Model\Account) {
                $did = max(0, (int) $default->getId());
                if ($did > 0 && !\in_array($did, $candidates, true)) {
                    $candidates[] = $did;
                }
            }
        } catch (\Throwable) {
            // continue
        }

        if (\function_exists('w_query')) {
            $list = w_query('cdn', 'getAccounts', ['adapter' => 'cloudflare']);
            if (\is_array($list)) {
                foreach ($list as $row) {
                    $id = (int) ($row['account_id'] ?? 0);
                    if ($id > 0 && !\in_array($id, $candidates, true)) {
                        $candidates[] = $id;
                    }
                }
            }
        }

        foreach ($candidates as $id) {
            try {
                /** @var \Weline\Cdn\Model\Account $account */
                $account = ObjectManager::getInstance(\Weline\Cdn\Model\Account::class, [], false);
                $account->clearQuery()->load($id);
                if ((int) $account->getId() !== $id) {
                    continue;
                }
                if (\strtolower(\trim((string) $account->getAdapter())) !== 'cloudflare') {
                    continue;
                }

                // 与邮局/CDN 同一套解析：credentialsForAccount 会合并 Token + Global Key
                $c = [];
                if (\class_exists(\Weline\Cdn\Service\CloudflareOAuthService::class)) {
                    try {
                        /** @var \Weline\Cdn\Service\CloudflareOAuthService $oauth */
                        $oauth = ObjectManager::getInstance(\Weline\Cdn\Service\CloudflareOAuthService::class);
                        $c = $oauth->credentialsForAccount($account);
                    } catch (\Throwable) {
                        $c = [];
                    }
                }
                if ($c === []) {
                    $c = $account->getCredentialsArray();
                }
                if (!\is_array($c) || $c === []) {
                    continue;
                }

                $email = \trim((string) ($c['email'] ?? $c['api_email'] ?? ''));
                $key = \trim((string) ($c['api_key'] ?? $c['global_api_key'] ?? ''));
                $token = \trim((string) ($c['api_token'] ?? ''));
                $authMode = \strtolower(\trim((string) ($c['auth_mode'] ?? '')));

                // 跟随 CDN credentialsForAccount 的 auth_mode（本站为 global）
                if ($email !== '' && $key !== '') {
                    $out = [
                        'email' => $email,
                        'api_key' => $key,
                        'cdn_account_id' => $id,
                        'auth_mode' => $authMode !== '' ? $authMode : 'global',
                    ];
                    if ($token !== '') {
                        $out['api_token'] = $token;
                    }

                    return $out;
                }
                if ($token !== '') {
                    return [
                        'api_token' => $token,
                        'cdn_account_id' => $id,
                        'auth_mode' => $authMode !== '' ? $authMode : 'token',
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveDefaultCloudflareCdnAccountId(): int
    {
        if (!\function_exists('w_query')) {
            return 0;
        }
        try {
            if (\class_exists(\Weline\Cdn\Service\AccountManager::class)) {
                /** @var \Weline\Cdn\Service\AccountManager $manager */
                $manager = ObjectManager::getInstance(\Weline\Cdn\Service\AccountManager::class);
                $account = $manager->getDefaultAccount('cloudflare');
                if ($account instanceof \Weline\Cdn\Model\Account) {
                    return max(0, (int) $account->getId());
                }
            }
        } catch (\Throwable) {
            // fall through
        }
        $list = w_query('cdn', 'getAccounts', ['adapter' => 'cloudflare']);
        if (!\is_array($list) || $list === []) {
            return 0;
        }
        $first = $list[0] ?? [];

        return max(0, (int) ($first['account_id'] ?? 0));
    }

    private function ensureRootDomain(
        string $root,
        string $dnsProvider,
        int $dnsAccountId,
        int $cdnAccountId,
    ): Domain {
        $domain = clone $this->domainModel;
        $domain->clearQuery()
            ->where(Domain::schema_fields_DOMAIN, $root)
            ->find()
            ->fetch();

        if ((int) $domain->getDomainId() <= 0) {
            $domain->clearData();
            $domain->setAccountId($dnsAccountId > 0 ? $dnsAccountId : 0);
            $domain->setDomain($root);
            $domain->setStatus(Domain::STATUS_ACTIVE);
            $domain->setResolveStatus(Domain::RESOLVE_STATUS_RESOLVED);
            $domain->setHttpsStatus(Domain::HTTPS_STATUS_NONE);
            $domain->setDnsCutoverComplete(1);
        }

        $domain->setDnsProvider($dnsProvider);
        $domain->setDnsAccountId($dnsAccountId);
        if ($cdnAccountId > 0) {
            $domain->setCdnProvider($dnsProvider);
            $domain->setCdnAccountId($cdnAccountId);
        }
        if ((int) $domain->getAccountId() <= 0 && $dnsAccountId > 0) {
            // 无独立注册商账户时，DNS 账户作为可见「服务商」关联，避免 UI 全「-」
            $domain->setAccountId($dnsAccountId);
        }
        $domain->forceCheck(false)->save();

        return $domain;
    }

    private function relinkOrphanPools(string $root, int $domainId, string $dnsProvider): void
    {
        $pool = clone $this->poolModel;
        $rows = $pool->clearQuery()
            ->where(DomainPool::schema_fields_ROOT_DOMAIN, $root)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            $poolId = (int) ($row[DomainPool::schema_fields_ID] ?? 0);
            if ($poolId <= 0) {
                continue;
            }
            $parent = (int) ($row[DomainPool::schema_fields_PARENT_DOMAIN_ID] ?? 0);
            $prov = \trim((string) ($row[DomainPool::schema_fields_DNS_PROVIDER] ?? ''));
            if ($parent === $domainId && $prov === $dnsProvider) {
                continue;
            }
            $m = clone $this->poolModel;
            $m->clearQuery()->load($poolId);
            if ($m->getPoolId() <= 0) {
                continue;
            }
            if ($parent <= 0) {
                $m->setParentDomainId($domainId);
            }
            if ($prov === '') {
                $m->setDnsProvider($dnsProvider);
                $m->setDnsStatus(DomainPool::INFRA_STATUS_READY);
            }
            $m->save();
        }
    }

    /**
     * @return array{
     *   success: bool,
     *   hostname: string,
     *   pool_id: int,
     *   created: bool,
     *   stage: string,
     *   https_status: string,
     *   message: string,
     *   certificate?: array<string, mixed>
     * }
     */
    private function ensurePoolHostname(
        string $hostname,
        string $root,
        int $domainId,
        string $dnsProvider,
        string $originIp,
        bool $requestCert,
        string $source,
    ): array {
        $pool = clone $this->poolModel;
        $pool->clearQuery()->loadByDomain($hostname);
        $created = $pool->getPoolId() <= 0;
        if ($created) {
            $pool->clearData();
            $pool->setDomain($hostname);
            $pool->setDescription((string) __('公网 DNS 通后自动入池（%{1}）', [$source]));
            $pool->setStatus(DomainPool::STATUS_ACTIVE);
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
        }

        $pool->setParentDomainId($domainId);
        $pool->setData(DomainPool::schema_fields_ROOT_DOMAIN, $root);
        $pool->setDnsProvider($dnsProvider);
        $pool->setDnsStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setCdnStatus(DomainPool::INFRA_STATUS_READY);
        $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_RESOLVED);
        $pool->setIsLocalServer(true);
        $pool->setResolveCheckedAt(\date('Y-m-d H:i:s'));
        $pool->setResolveError('');
        $pool->setConnectivityStatus(DomainPool::CONNECTIVITY_OK);
        $pool->setConnectivityCheckedAt(\date('Y-m-d H:i:s'));
        if ($originIp !== '') {
            if (\str_contains($originIp, ':')) {
                $pool->setResolvedIpv6($originIp);
            } else {
                $pool->setResolvedIp($originIp);
            }
        }

        $https = \strtolower(\trim((string) $pool->getHttpsStatus()));
        if ($https === DomainPool::HTTPS_STATUS_VALID) {
            $pool->setPoolLifecycleStage(DomainPool::LIFECYCLE_CERT_VALID);
            $pool->calculateSiteReady();
            $pool->save();
        } else {
            $pool->calculateSiteReady();
            $pool->save();
            $this->lifecycle->applyAfterResolvePass($pool, [
                'resolved' => true,
                'is_local' => true,
            ]);
            $pool->clearQuery()->loadByDomain($hostname);
        }

        $poolId = (int) $pool->getPoolId();
        if ($poolId <= 0) {
            return [
                'success' => false,
                'hostname' => $hostname,
                'pool_id' => 0,
                'created' => $created,
                'stage' => '',
                'https_status' => '',
                'message' => (string) __('域名池保存失败'),
            ];
        }

        if ($created) {
            $this->flowLog->append(
                $poolId,
                DomainPoolFlowLog::KIND_POOL_CREATED,
                (string) __('公网 DNS 通后自动入池：%{1}', [$hostname]),
            );
        }

        $certResult = null;
        $httpsNow = \strtolower(\trim((string) $pool->getHttpsStatus()));
        if ($requestCert && $httpsNow !== DomainPool::HTTPS_STATUS_VALID) {
            $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_PENDING);
            $pool->setHttpsError('');
            $this->lifecycle->markCertPending($pool);
            $this->flowLog->append(
                $poolId,
                DomainPoolFlowLog::KIND_CERT_START,
                (string) __('公网 DNS 通后立即申请证书：%{1}', [$hostname]),
            );

            try {
                $certResult = $this->certificateRequest->requestCertificate([
                    'domain' => $hostname,
                    'email' => 'admin@' . $root,
                    'provider' => 'letsencrypt',
                    'cert_type' => 'exact',
                    'pool_id' => $poolId,
                    'domain_id' => $domainId,
                    'challenge_strategy' => 'dns01',
                ]);
            } catch (\Throwable $e) {
                $certResult = [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }

            $pool->clearQuery()->loadByDomain($hostname);
            if (($certResult['success'] ?? false) === true) {
                $certId = (int) ($certResult['cert_id'] ?? 0);
                if ($certId > 0) {
                    $pool->setCertId($certId);
                }
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_VALID);
                $pool->setHttpsError('');
                $this->lifecycle->markCertValid($pool);
                $pool->calculateSiteReady();
                $pool->save();
                $this->flowLog->append(
                    $poolId,
                    DomainPoolFlowLog::KIND_CERT_OK,
                    (string) __('证书有效：%{1}', [$hostname]),
                );
            } else {
                $msg = (string) ($certResult['message'] ?? __('证书申请失败'));
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_ERROR);
                $pool->setHttpsError($msg);
                $this->lifecycle->markOriginReadyAfterCertFailure($pool);
                $this->flowLog->append($poolId, DomainPoolFlowLog::KIND_CERT_FAIL, $msg);

                return [
                    'success' => false,
                    'hostname' => $hostname,
                    'pool_id' => $poolId,
                    'created' => $created,
                    'stage' => (string) $pool->getPoolLifecycleStage(),
                    'https_status' => DomainPool::HTTPS_STATUS_ERROR,
                    'message' => $msg,
                    'certificate' => \is_array($certResult) ? $certResult : [],
                ];
            }
        }

        $pool->clearQuery()->loadByDomain($hostname);

        return [
            'success' => true,
            'hostname' => $hostname,
            'pool_id' => $poolId,
            'created' => $created,
            'stage' => (string) $pool->getPoolLifecycleStage(),
            'https_status' => (string) $pool->getHttpsStatus(),
            'message' => (string) __('已入池'),
            'certificate' => \is_array($certResult) ? $certResult : [],
        ];
    }

    private function normalizeHostname(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \rtrim($value, '.');
        if ($value === '' || \in_array($value, ['127.0.0.1', '0.0.0.0', 'localhost'], true)) {
            return '';
        }

        return $value;
    }

    /**
     * @param list<string>|string $raw
     * @return list<string>
     */
    private function normalizeHostnameList(array|string $raw): array
    {
        if (\is_string($raw)) {
            $raw = \preg_split('/[\s,;]+/', $raw) ?: [];
        }
        $out = [];
        foreach ($raw as $item) {
            $hn = $this->normalizeHostname((string) $item);
            if ($hn !== '' && !\in_array($hn, $out, true)) {
                $out[] = $hn;
            }
        }

        return $out;
    }
}
