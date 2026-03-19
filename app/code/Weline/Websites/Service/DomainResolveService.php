<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名解析服务
 *
 * 负责检测域名解析状态、执行自动解析、验证解析结果
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainConfig;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainDnsRecord;
use Weline\Websites\Model\DomainRegistrarAccount;

class DomainResolveService
{
    private const DNS_RESOLVE_TIMEOUT = 5;

    private Domain $domainModel;
    private DomainConfig $domainConfig;
    private DomainDnsRecord $dnsRecordModel;
    private ServerIpService $serverIpService;
    private DnsProviderDetector $dnsDetector;
    private DomainRegistrarResolverService $registrarResolver;
    private DomainOriginMatchService $originMatch;

    public function __construct(
        Domain $domainModel,
        DomainConfig $domainConfig,
        DomainDnsRecord $dnsRecordModel,
        ServerIpService $serverIpService,
        DnsProviderDetector $dnsDetector,
        DomainRegistrarResolverService $registrarResolver,
    ) {
        $this->domainModel = $domainModel;
        $this->domainConfig = $domainConfig;
        $this->dnsRecordModel = $dnsRecordModel;
        $this->serverIpService = $serverIpService;
        $this->dnsDetector = $dnsDetector;
        $this->registrarResolver = $registrarResolver;
        // 不在构造器注入：避免 compiled_factories 在部分环境下将第 7 参错解析为 AuthoritativeDnsOriginService
        $this->originMatch = ObjectManager::getInstance(DomainOriginMatchService::class);
    }

    /**
     * 检测单个域名的解析状态
     *
     * @param Domain $domain 域名模型
     * @return array{resolved: bool, ipv4: string, ipv6: string, is_local: bool, error: string}
     */
    public function checkResolve(Domain $domain): array
    {
        $domainName = $domain->getDomain();
        $now = \date('Y-m-d H:i:s');

        $error = '';
        $recordIps = $this->originMatch->collectPublicAaaaRecordIps($domainName);
        $ipv4List = $recordIps['ipv4'];
        $ipv6List = $recordIps['ipv6'];

        $ipv4 = $ipv4List !== [] ? $ipv4List[0] : '';
        $ipv6 = $ipv6List !== [] ? $ipv6List[0] : '';
        if ($ipv4 === '' && $ipv6 === '') {
            $error = __('未解析到有效 IP，请检查域名是否已添加 A 或 AAAA 记录');
        }

        $resolved = $ipv4 !== '' || $ipv6 !== '';
        $zone = \strtolower(\trim($domainName));
        $isLocal = $this->originMatch->fqdnPointsToServer(
            $domainName,
            $zone,
            (int) $domain->getDnsAccountId(),
            (int) $domain->getCdnAccountId(),
        );

        $resolveStatus = $resolved ? Domain::RESOLVE_STATUS_RESOLVED : Domain::RESOLVE_STATUS_ERROR;

        if ($error !== '' && !$resolved) {
            $resolveStatus = Domain::RESOLVE_STATUS_ERROR;
        }

        // 更新域名模型
        $domain->setResolvedIp($ipv4);
        $domain->setResolvedIpv6($ipv6);
        $domain->setIsLocalServer($isLocal);
        $domain->setResolveStatus($resolveStatus);
        $domain->setResolveCheckedAt($now);
        $domain->setResolveError(\trim($error, '; '));
        $domain->save();

        return [
            'resolved' => $resolved,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'is_local' => $isLocal,
            'error' => \trim($error, '; '),
        ];
    }

    /**
     * 批量检测域名解析状态
     *
     * @param array $domainIds 域名 ID 数组
     * @return array{checked: int, resolved: int, local: int, errors: int}
     */
    public function batchCheckResolve(array $domainIds): array
    {
        $checked = 0;
        $resolved = 0;
        $local = 0;
        $errors = 0;

        $domains = $this->domainModel->getByIds($domainIds);

        foreach ($domains as $row) {
            $domain = clone $this->domainModel;
            $domain->setData($row);

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
        }

        return [
            'checked' => $checked,
            'resolved' => $resolved,
            'local' => $local,
            'errors' => $errors,
        ];
    }

    /**
     * 为域名添加 A/AAAA 记录（指向本服务器）
     *
     * @param Domain $domain 域名模型
     * @param array $subdomains 子域列表（如 ['@', 'www']）
     * @return array{success: bool, added: int, errors: array}
     */
    public function autoResolveToLocal(Domain $domain, array $subdomains = []): array
    {
        if ($subdomains === []) {
            $subdomains = $this->domainConfig->getAutoResolveSubdomains();
        }

        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();

        if ($serverIpv4 === '' && $serverIpv6 === '') {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [__('无法获取服务器公网 IP')],
            ];
        }

        // 获取注册商适配器
        $account = $this->loadAccount($domain->getAccountId());
        if ($account === null) {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [__('找不到域名商账户')],
            ];
        }

        $adapter = $this->registrarResolver->getAdapter($account->getRegistrarCode());
        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [__('域名商不支持 DNS 管理')],
            ];
        }

        $credentials = $account->getCredentials();
        $domainName = $domain->getDomain();
        $recordType = $this->domainConfig->getAutoResolveRecordType();

        $records = [];
        foreach ($subdomains as $host) {
            $host = \trim($host);
            if ($host === '') {
                continue;
            }
            if (DnsSiteHostRules::isUnderscoreTechnicalDnsHost($host)) {
                continue;
            }

            if ($recordType === 'A' && $serverIpv4 !== '') {
                $records[] = [
                    'type' => 'A',
                    'host' => $host,
                    'value' => $serverIpv4,
                    'ttl' => 600,
                ];
            } elseif ($recordType === 'AAAA' && $serverIpv6 !== '') {
                $records[] = [
                    'type' => 'AAAA',
                    'host' => $host,
                    'value' => $serverIpv6,
                    'ttl' => 600,
                ];
            } elseif ($recordType === 'BOTH') {
                if ($serverIpv4 !== '') {
                    $records[] = [
                        'type' => 'A',
                        'host' => $host,
                        'value' => $serverIpv4,
                        'ttl' => 600,
                    ];
                }
                if ($serverIpv6 !== '') {
                    $records[] = [
                        'type' => 'AAAA',
                        'host' => $host,
                        'value' => $serverIpv6,
                        'ttl' => 600,
                    ];
                }
            }
        }

        if ($records === []) {
            return [
                'success' => false,
                'added' => 0,
                'errors' => [__('没有可添加的解析记录')],
            ];
        }

        $result = $adapter->batchAddDnsRecords($domainName, $records, $credentials);

        return [
            'success' => $result['success'],
            'added' => $result['added'],
            'errors' => $result['errors'] ?? [],
        ];
    }

    /**
     * 获取域名的 DNS 管理账户和适配器
     *
     * 优先级：
     * 1. 域名指定的 dns_account_id
     * 2. 根据 dns_provider 自动查找对应账户（CDN 服务商如 Cloudflare）
     * 3. 域名注册商账户
     *
     * @param Domain $domain 域名模型
     * @param bool $autoSave 是否自动保存找到的 DNS 账户关联
     * @return array{account: DomainRegistrarAccount|null, adapter: \Weline\Websites\Api\DomainRegistrarInterface|null, error: string}
     */
    public function getDnsManagementAccount(Domain $domain, bool $autoSave = true): array
    {
        $dnsAccountId = $domain->getDnsAccountId();
        $dnsProvider = $domain->getDnsProvider();
        
        $account = null;
        $adapter = null;
        
        // 1. 如果有专门的 DNS 账户，优先使用
        if ($dnsAccountId > 0) {
            $account = $this->loadAccount($dnsAccountId);
            if ($account !== null) {
                $adapter = $this->registrarResolver->getAdapter($account->getRegistrarCode());
            }
        }
        
        // 2. 如果没有 DNS 账户但 DNS 服务商是已知的 CDN/DNS 服务商，尝试查找对应账户
        if ($adapter === null && $dnsProvider !== '' && $this->dnsDetector->isCdnProvider($dnsProvider)) {
            $account = $this->findAccountByProviderCode($dnsProvider);
            if ($account !== null) {
                $adapter = $this->registrarResolver->getAdapter($account->getRegistrarCode());
                // 自动关联 DNS 账户
                if ($autoSave) {
                    $domain->setDnsAccountId($account->getAccountId());
                    $domain->forceCheck(false)->save();
                }
            }
        }
        
        // 3. 最后尝试使用注册商账户
        if ($adapter === null) {
            $account = $this->loadAccount($domain->getAccountId());
            if ($account !== null) {
                $adapter = $this->registrarResolver->getAdapter($account->getRegistrarCode());
            }
        }
        
        if ($account === null) {
            return [
                'account' => null,
                'adapter' => null,
                'error' => __('找不到域名商账户'),
            ];
        }

        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            // 如果 DNS 服务商与注册商不同，提供更明确的错误信息
            if ($dnsProvider !== '' && $account->getRegistrarCode() !== $dnsProvider) {
                return [
                    'account' => null,
                    'adapter' => null,
                    'error' => __('域名 DNS 托管在 %{1}，但未配置该服务商账户。请在「域名商账户」中添加 %{1} 账户。', [$this->dnsDetector->getProviderDisplayName($dnsProvider)]),
                ];
            }
            return [
                'account' => null,
                'adapter' => null,
                'error' => __('域名商不支持 DNS 管理'),
            ];
        }

        if ($autoSave && (int) $domain->getDnsAccountId() <= 0) {
            $domain->setDnsAccountId((int) $account->getAccountId());
            $domain->forceCheck(false)->save();
        }

        return [
            'account' => $account,
            'adapter' => $adapter,
            'error' => '',
        ];
    }

    /**
     * 将 dns_account_id 从 0 解析为可管理 DNS 的账户并落库（购买/注册商拉取/DNS 切换前调用）。
     *
     * 优先级：已写入的 dns_provider 对应账户 → NS 识别服务商账户 → 注册商账户（同源且适配器支持 DNS API）。
     */
    public function ensureDnsAccountIdPersisted(Domain $domain): bool
    {
        if ((int) $domain->getDnsAccountId() > 0) {
            return true;
        }
        if (!(int) $domain->getDomainId()) {
            return false;
        }

        $dnsProvider = \strtolower(\trim((string) $domain->getDnsProvider()));

        if ($dnsProvider !== '') {
            $acc = $this->findAccountByProviderCode($dnsProvider);
            if ($acc !== null) {
                $adapter = $this->registrarResolver->getAdapter($acc->getRegistrarCode());
                if ($adapter !== null && $adapter->supportsDnsManagement()) {
                    $domain->setDnsAccountId((int) $acc->getAccountId());
                    $domain->forceCheck(false)->save();

                    return true;
                }
            }
        }

        $ns = $domain->getNameservers();
        if (\is_array($ns) && $ns !== []) {
            $detected = \strtolower(\trim((string) $this->dnsDetector->detectProvider($ns)));
            if ($detected !== '' && $detected !== 'unknown') {
                if ($dnsProvider === '') {
                    $domain->setDnsProvider($detected);
                    $dnsProvider = $detected;
                }
                $acc = $this->findAccountByProviderCode($detected);
                if ($acc !== null) {
                    $adapter = $this->registrarResolver->getAdapter($acc->getRegistrarCode());
                    if ($adapter !== null && $adapter->supportsDnsManagement()) {
                        $domain->setDnsAccountId((int) $acc->getAccountId());
                        $domain->forceCheck(false)->save();

                        return true;
                    }
                }
            }
        }

        $regAcc = $this->loadAccount((int) $domain->getAccountId());
        if ($regAcc !== null) {
            $adapter = $this->registrarResolver->getAdapter($regAcc->getRegistrarCode());
            if ($adapter !== null && $adapter->supportsDnsManagement()) {
                $domain->setDnsAccountId((int) $regAcc->getAccountId());
                if (\strtolower(\trim((string) $domain->getDnsProvider())) === '') {
                    $domain->setDnsProvider(\strtolower((string) $regAcc->getRegistrarCode()));
                }
                $domain->forceCheck(false)->save();

                return true;
            }
        }

        w_log_warning(
            __('[DomainResolve] 无法补全 dns_account_id：domain_id=%{1} %{2}', [(string) $domain->getDomainId(), $domain->getDomain()]),
            [],
            'domain_resolve'
        );

        return false;
    }

    /**
     * 同步域名的 DNS 记录到本地
     *
     * @param Domain $domain 域名模型
     * @return array{synced: int, added: int, updated: int, deleted: int, error: string}
     */
    public function syncDnsRecords(Domain $domain): array
    {
        $result = $this->getDnsManagementAccount($domain);
        
        if ($result['error'] !== '') {
            return [
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'error' => $result['error'],
            ];
        }

        $account = $result['account'];
        $adapter = $result['adapter'];

        try {
            $remoteRecords = $adapter->getDnsRecords($domain->getDomain(), $account->getCredentials());
        } catch (\Throwable $e) {
            return [
                'synced' => 0,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'error' => $e->getMessage(),
            ];
        }

        // 转换为本地模型格式
        $records = [];
        foreach ($remoteRecords as $r) {
            $records[] = [
                'type' => $r['type'] ?? 'A',
                'host' => $r['host'] ?? '@',
                'value' => $r['value'] ?? '',
                'ttl' => $r['ttl'] ?? 600,
                'priority' => $r['priority'] ?? 0,
                'remote_record_id' => $r['record_id'] ?? '',
            ];
        }

        $result = $this->dnsRecordModel->syncRecords($domain->getDomainId(), $records);
        $result['error'] = '';

        return $result;
    }

    /**
     * 将本地 DNS 记录推送到指定供应商
     * 用于 DNS 切换时，把当前记录自动同步到目标供应商
     *
     * @param Domain $domain 域名模型
     * @param DomainRegistrarAccount $targetAccount 目标 DNS 管理账户
     * @param array|null $records 记录数组，null 则从本地 DB 读取
     * @return array{success: bool, added: int, failed: int, errors: array}
     */
    public function pushRecordsToProvider(Domain $domain, DomainRegistrarAccount $targetAccount, ?array $records = null): array
    {
        $adapter = $this->registrarResolver->getAdapter($targetAccount->getRegistrarCode());
        if ($adapter === null || !$adapter->supportsDnsManagement()) {
            return [
                'success' => false,
                'added' => 0,
                'failed' => 0,
                'errors' => [__('目标账户不支持 DNS 记录管理')],
            ];
        }

        if ($records === null) {
            $rows = $this->dnsRecordModel->getByDomainId($domain->getDomainId());
            $records = [];
            foreach ($rows as $row) {
                $type = \strtoupper($row[DomainDnsRecord::schema_fields_RECORD_TYPE] ?? 'A');
                if ($type === 'NS') {
                    continue;
                }
                $value = $row[DomainDnsRecord::schema_fields_VALUE] ?? '';
                if ($value === '') {
                    continue;
                }
                $records[] = [
                    'type' => $type,
                    'host' => $row[DomainDnsRecord::schema_fields_HOST] ?? '@',
                    'value' => $value,
                    'ttl' => (int) ($row[DomainDnsRecord::schema_fields_TTL] ?? 600),
                    'priority' => (int) ($row[DomainDnsRecord::schema_fields_PRIORITY] ?? 0),
                ];
            }
        } else {
            $records = \array_values(\array_filter($records, static function ($r) {
                $type = \strtoupper($r['type'] ?? 'A');
                $value = \trim($r['value'] ?? '');
                return $type !== 'NS' && $value !== '';
            }));
        }

        if ($records !== []) {
            $records = $adapter->applyCdnSettingsToDnsRecords($domain->getDomain(), $records);
        }

        if ($records === []) {
            return [
                'success' => true,
                'added' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $credentials = $targetAccount->getCredentials();
        $result = $adapter->batchAddDnsRecords($domain->getDomain(), $records, $credentials);

        return [
            'success' => $result['success'] ?? false,
            'added' => (int) ($result['added'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'errors' => (array) ($result['errors'] ?? []),
        ];
    }

    /**
     * 获取可用于推送到目标供应商的记录（本地 DB 或从当前供应商拉取）
     *
     * @return array 适配 addDnsRecord 格式的数组
     */
    public function getRecordsForPush(Domain $domain): array
    {
        $rows = $this->dnsRecordModel->getByDomainId($domain->getDomainId());
        if ($rows !== []) {
            $out = [];
            foreach ($rows as $row) {
                $type = \strtoupper($row[DomainDnsRecord::schema_fields_RECORD_TYPE] ?? 'A');
                if ($type === 'NS') {
                    continue;
                }
                $value = $row[DomainDnsRecord::schema_fields_VALUE] ?? '';
                if ($value === '') {
                    continue;
                }
                $out[] = [
                    'type' => $type,
                    'host' => $row[DomainDnsRecord::schema_fields_HOST] ?? '@',
                    'value' => $value,
                    'ttl' => (int) ($row[DomainDnsRecord::schema_fields_TTL] ?? 600),
                    'priority' => (int) ($row[DomainDnsRecord::schema_fields_PRIORITY] ?? 0),
                ];
            }
            return $out;
        }

        $dnsResult = $this->getDnsManagementAccount($domain, false);
        if ($dnsResult['error'] !== '' || $dnsResult['adapter'] === null) {
            return [];
        }

        try {
            $remoteRecords = $dnsResult['adapter']->getDnsRecords($domain->getDomain(), $dnsResult['account']->getCredentials());
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($remoteRecords as $r) {
            $type = \strtoupper($r['type'] ?? 'A');
            if ($type === 'NS') {
                continue;
            }
            $value = \trim($r['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $out[] = [
                'type' => $type,
                'host' => $r['host'] ?? '@',
                'value' => $value,
                'ttl' => (int) ($r['ttl'] ?? 600),
                'priority' => (int) ($r['priority'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * 获取域名的 DNS 记录详情（包含解析状态）
     *
     * @param Domain $domain 域名模型
     * @return array{records: array, dns_provider: array, nameservers: array}
     */
    public function getDnsDetails(Domain $domain): array
    {
        $records = $this->dnsRecordModel->getByDomainId($domain->getDomainId());
        $storedNameservers = $domain->getNameservers();
        $liveNameservers = $this->queryLiveNsRecords($domain->getDomain());
        $nameservers = $liveNameservers !== [] ? $liveNameservers : $storedNameservers;

        // 获取注册商代码
        $account = $this->loadAccount($domain->getAccountId());
        $registrarCode = $account !== null ? $account->getRegistrarCode() : '';

        // 检测 DNS 服务商
        $dnsInfo = $this->dnsDetector->detect($nameservers, $registrarCode);
        if ($dnsInfo['provider'] === 'unknown') {
            $savedProvider = \strtolower(\trim($domain->getDnsProvider()));
            if ($savedProvider !== '' && $savedProvider !== 'unknown') {
                $dnsInfo = [
                    'provider' => $savedProvider,
                    'name' => $this->dnsDetector->getProviderDisplayName($savedProvider),
                    'is_original' => $this->dnsDetector->isOriginalProvider($savedProvider, $registrarCode),
                    'color' => $this->dnsDetector->getProviderColor($savedProvider, $registrarCode),
                    'original_registrar' => $this->dnsDetector->isOriginalProvider($savedProvider, $registrarCode)
                        ? ''
                        : $this->dnsDetector->getProviderDisplayName($registrarCode),
                ];
            }
        }

        $hasNameserverChanges = $liveNameservers !== [] && $liveNameservers !== $storedNameservers;
        $hasProviderChanges = ($dnsInfo['provider'] ?? 'unknown') !== 'unknown'
            && $domain->getDnsProvider() !== ($dnsInfo['provider'] ?? '');

        if ($hasNameserverChanges) {
            $domain->setNameservers($liveNameservers);
        }
        if ($hasProviderChanges) {
            $domain->setDnsProvider((string)$dnsInfo['provider']);
        }
        if ($hasNameserverChanges || $hasProviderChanges) {
            $domain->save();
        }

        // 检查每条记录是否指向本服务器
        $serverIpv4 = $this->serverIpService->getPublicIpv4();
        $serverIpv6 = $this->serverIpService->getPublicIpv6();

        foreach ($records as &$record) {
            $isLocal = false;
            $type = \strtoupper($record[DomainDnsRecord::schema_fields_RECORD_TYPE] ?? '');
            $value = $record[DomainDnsRecord::schema_fields_VALUE] ?? '';

            if ($type === 'A' && $value === $serverIpv4) {
                $isLocal = true;
            } elseif ($type === 'AAAA' && \strtolower($value) === \strtolower($serverIpv6)) {
                $isLocal = true;
            }

            $record['is_local_ip'] = $isLocal;
        }
        unset($record);

        return [
            'records' => $records,
            'dns_provider' => $dnsInfo,
            'nameservers' => $nameservers,
        ];
    }

    /**
     * 查询域名当前生效的 NS 记录（用于 DNS 切换后等待传播）
     */
    public function getLiveNameservers(string $domain): array
    {
        return $this->queryLiveNsRecords($domain);
    }

    /**
     * 通过 Cloudflare 1.1.1.1 DoH（application/dns-json）查询 NS，与 {@see getLiveNameservers} 系统解析器结果交叉比对，
     * 减轻「本机 libc 仍缓存旧 NS」导致的误判；失败时返回空数组（不阻断主流程）。
     *
     * @return list<string>
     */
    public function getLiveNameserversViaCloudflareDoH(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return [];
        }
        $url = 'https://cloudflare-dns.com/dns-query?name=' . \rawurlencode($domain) . '&type=NS';
        $ctx = \stream_context_create([
            'http' => [
                'timeout' => 4,
                'header' => "Accept: application/dns-json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @\file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($data) || empty($data['Answer']) || !\is_array($data['Answer'])) {
            return [];
        }
        $nameservers = [];
        foreach ($data['Answer'] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((int) ($row['type'] ?? 0) !== 2) {
                continue;
            }
            $t = \strtolower(\trim((string) ($row['data'] ?? '')));
            if ($t !== '') {
                $nameservers[] = \rtrim($t, '.');
            }
        }
        $nameservers = \array_values(\array_unique($nameservers));
        \sort($nameservers);

        return $nameservers;
    }

    private function queryLiveNsRecords(string $domain): array
    {
        try {
            $records = @\dns_get_record($domain, \DNS_NS);
            if ($records === false || $records === []) {
                return [];
            }

            $nameservers = [];
            foreach ($records as $record) {
                $target = \strtolower(\trim((string)($record['target'] ?? '')));
                if ($target !== '') {
                    $nameservers[] = \rtrim($target, '.');
                }
            }

            $nameservers = \array_values(\array_unique($nameservers));
            \sort($nameservers);
            return $nameservers;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 通过 {@see Domain::getAccountId} 注册商 API 读取注册局登记的委派 NS（如 Gname `getDomainDetail` 的 ymdns）。
     *
     * @return list<string> 小写、无尾点，已排序去重
     */
    private function fetchRegistrarDelegatedNameservers(Domain $domain): array
    {
        $accountId = (int) $domain->getAccountId();
        if ($accountId <= 0) {
            return [];
        }
        $acc = $this->loadAccount($accountId);
        if ($acc === null) {
            return [];
        }
        $adapter = $this->registrarResolver->getAdapter($acc->getRegistrarCode());
        if ($adapter === null || !$adapter->isDomainRegistrar()) {
            return [];
        }
        try {
            $detail = $adapter->getDomainDetail($domain->getDomain(), $acc->getCredentials());
        } catch (\Throwable) {
            return [];
        }
        $ns = $detail['nameservers'] ?? [];
        if (!\is_array($ns)) {
            return [];
        }
        $out = [];
        foreach ($ns as $h) {
            $t = \rtrim(\strtolower(\trim((string) $h)), '.');
            if ($t !== '') {
                $out[] = $t;
            }
        }
        $out = \array_values(\array_unique($out));
        \sort($out);

        return $out;
    }

    /**
     * ACME DNS-01：仅用「注册商适配器（account_id）+ 当前 DNS 托管适配器」API 判断是否具备写入验证 TXT 的前提。
     * 不使用本机 {@see dns_get_record}、公共 DNS/DoH 或轮询等待公网传播。
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   matched_via?: string,
     *   registrar_ns?: array<string>,
     *   registrar_detected?: string,
     *   zone_status?: string
     * }
     */
    public function validateAcmeDns01HostingViaAdapters(
        Domain $domain,
        string $rootDomain,
        string $providerCode,
        DomainRegistrarInterface $dnsAdapter,
        array $dnsCredentials
    ): array {
        $rootDomain = \strtolower(\trim($rootDomain));
        $providerCode = \strtolower(\trim($providerCode));
        if ($rootDomain === '' || $providerCode === '') {
            return ['ok' => false, 'message' => (string) __('参数无效')];
        }

        $regNs = [];
        $regDetected = 'unknown';
        if ((int) $domain->getAccountId() > 0) {
            $regNs = $this->fetchRegistrarDelegatedNameservers($domain);
            if ($regNs !== []) {
                $regDetected = $this->dnsDetector->detectProvider($regNs);
                if ($regDetected !== $providerCode) {
                    $regStr = \implode(', ', $regNs);

                    return [
                        'ok' => false,
                        'message' => (string) __(
                            '注册商 API 显示的委派 NS 为「%{1}」（%{2}），未指向当前 DNS 托管「%{3}」。请先在注册商处将 NS 改为该托管商要求的地址后再申请证书。',
                            [$regStr, $this->dnsDetector->getProviderDisplayName($regDetected), $this->dnsDetector->getProviderDisplayName($providerCode)]
                        ),
                        'registrar_ns' => $regNs,
                        'registrar_detected' => $regDetected,
                    ];
                }
            }
        }

        $zoneProbe = $this->verifyDnsManagedZoneWritableForAcme($dnsAdapter, $rootDomain, $dnsCredentials);
        if (!$zoneProbe['ok']) {
            return [
                'ok' => false,
                'message' => (string) ($zoneProbe['message'] ?? __('DNS 托管 API 未能确认该域名在本账户下可写')),
                'registrar_ns' => $regNs,
                'registrar_detected' => $regDetected,
            ];
        }

        $zoneStatus = (string) ($zoneProbe['zone_status'] ?? '');
        $expName = $this->dnsDetector->getProviderDisplayName($providerCode);
        $matchedVia = $regNs !== [] && $regDetected === $providerCode ? 'registrar+dns' : 'dns_adapter';
        $zoneLabel = $zoneStatus !== '' ? $zoneStatus : (string) __('已就绪');
        $info = '';
        if ($regNs !== []) {
            $regStr = \implode(', ', $regNs);
            $info = (string) __(
                '[注册商 API] 委派 NS：%{1}（已识别为「%{2}」）。[DNS 托管 API] Zone 状态：%{3}。将直接写入验证 TXT。',
                [$regStr, $expName, $zoneLabel]
            );
        } else {
            $info = (string) __(
                '[DNS 托管 API] 已在本账户下确认域名可管理（Zone：%{1}）。未能通过注册商 API 读取委派 NS；若 CA 验证失败请核对注册局 NS 是否已指向「%{2}」。',
                [$zoneLabel, $expName]
            );
        }
        $info .= ' ' . (string) __(
            '证书机构仍从全球递归查询 TXT；若委派尚未在全球生效，DNS-01 可能失败，可稍后重试或改用 HTTP-01。'
        );

        return [
            'ok' => true,
            'message' => $info,
            'matched_via' => $matchedVia,
            'registrar_ns' => $regNs,
            'registrar_detected' => $regDetected,
            'zone_status' => $zoneStatus,
        ];
    }

    /**
     * 通过 DNS 托管适配器 {@see DomainRegistrarInterface::getDomainDetail}（及必要时 {@see getDnsRecords}）确认根域可写。
     *
     * @return array{ok: bool, message?: string, zone_status?: string}
     */
    private function verifyDnsManagedZoneWritableForAcme(
        DomainRegistrarInterface $adapter,
        string $rootDomain,
        array $credentials
    ): array {
        try {
            $detail = $adapter->getDomainDetail($rootDomain, $credentials);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!\is_array($detail)) {
            return ['ok' => false, 'message' => (string) __('DNS 托管 API 返回异常')];
        }
        $st = \strtolower(\trim((string) ($detail['status'] ?? '')));
        if ($st === 'not_found') {
            $msg = \trim((string) ($detail['message'] ?? ''));

            return [
                'ok' => false,
                'message' => $msg !== '' ? $msg : (string) __('DNS 托管账户下未找到该域名，请检查账户、Token 权限与区域资源'),
            ];
        }
        if (\in_array($st, ['deleted', 'error'], true)) {
            $msg = \trim((string) ($detail['message'] ?? ''));

            return [
                'ok' => false,
                'message' => $msg !== '' ? $msg : (string) __('DNS 托管侧域名状态异常，无法写入验证记录'),
            ];
        }
        if ($st === 'unknown' || $st === '') {
            try {
                $adapter->getDnsRecords($rootDomain, $credentials);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'zone_status' => $st !== '' ? $st : 'ready'];
    }

    /**
     * 校验向互联网宣告的权威 NS（与 dig/nslookup NS 一致）是否由指定 DNS 供应商托管。
     * 注意：注册局登记的委派 NS **只应在注册商处修改**；默认以公网可见结果为准（与 CA DNS-01 一致）。
     *
     * **ACME 专用**：`addAcmeTxtRecord` 已改为 {@see validateAcmeDns01HostingViaAdapters}（仅适配器 API），不再使用本方法与公网/本机探测做门闸。
     *
     * **Cutover / 门闸**：`DnsSwitchService` 等须保持 `trustRegistrarWhenPublicMismatch=false`，避免未传播即误判已完成切换。
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   live_ns: array<string>,
     *   detected: string,
     *   matched_via?: string,
     *   registrar_ns?: array<string>,
     *   registrar_detected?: string
     * }
     */
    public function validateAuthoritativeDnsMatchesProvider(
        string $rootDomain,
        string $providerCode,
        ?Domain $domain = null,
        bool $trustRegistrarWhenPublicMismatch = false
    ): array {
        $rootDomain = \strtolower(\trim($rootDomain));
        $providerCode = \strtolower(\trim($providerCode));
        if ($rootDomain === '' || $providerCode === '') {
            return ['ok' => false, 'message' => (string) __('参数无效'), 'live_ns' => [], 'detected' => 'unknown'];
        }
        $live = $this->getLiveNameservers($rootDomain);
        $detected = $this->dnsDetector->detectProvider($live);
        if ($live === []) {
            return [
                'ok' => false,
                'message' => (string) __(
                    '无法查询域名「%{1}」的权威 NS（与 dig/nslookup 结果一致）。请确认根域正确；在权威 NS 尚未指向当前 DNS 托管商前，勿用其 API 做证书 DNS 验证。',
                    [$rootDomain]
                ),
                'live_ns' => [],
                'detected' => 'unknown',
            ];
        }
        if ($detected === $providerCode) {
            return [
                'ok' => true,
                'message' => '',
                'live_ns' => $live,
                'detected' => $detected,
                'matched_via' => 'public',
            ];
        }

        $nsStr = \implode(', ', $live);
        $detName = $this->dnsDetector->getProviderDisplayName($detected);
        $expName = $this->dnsDetector->getProviderDisplayName($providerCode);

        if ($trustRegistrarWhenPublicMismatch && $domain !== null && (int) $domain->getDomainId() > 0) {
            $regNs = $this->fetchRegistrarDelegatedNameservers($domain);
            $regDetected = $regNs !== [] ? $this->dnsDetector->detectProvider($regNs) : 'unknown';
            if ($regNs !== [] && $regDetected === $providerCode) {
                $regStr = \implode(', ', $regNs);

                return [
                    'ok' => true,
                    'message' => (string) __(
                        '[注册商 API] 登记 NS 已为「%{1}」（%{2}），与当前 DNS 账户一致；本机/递归查询仍为「%{3}」（%{4}）。证书流程将先等待公网权威 NS 与目标一致后再写入验证 TXT（超时见 env websites.acme_dns）。CA 仍从全球递归查 TXT，若最终验证失败可再试或换 HTTP-01。',
                        [$expName, $regStr, $detName, $nsStr]
                    ),
                    'live_ns' => $live,
                    'detected' => $detected,
                    'matched_via' => 'registrar',
                    'registrar_ns' => $regNs,
                    'registrar_detected' => $regDetected,
                ];
            }
        }

        $suffix = '';
        if ($providerCode === 'cloudflare' && \in_array($detected, ['share_dns', 'gname', 'godaddy', 'unknown'], true)) {
            $suffix = ' ' . (string) __(
                '请在域名注册商处把 NS 改为 Cloudflare 控制台该域名页提供的两条 NS，等待全球生效（常需数小时）后再用 DNS-01 申请证书；或若 80 端口已指向本机可改用 HTTP-01。'
            );
        }
        $boundCf = false;
        try {
            $dm = ObjectManager::getInstance(Domain::class, [], false);
            $dm->clearQuery()->where(Domain::schema_fields_DOMAIN, $rootDomain)->find()->fetch();
            if ($dm->getDomainId() > 0 && \strtolower(\trim((string) $dm->getDnsProvider())) === 'cloudflare') {
                $boundCf = true;
            }
        } catch (\Throwable) {
        }
        if ($boundCf && $providerCode === 'cloudflare' && $detected !== 'cloudflare') {
            $suffix .= ' ' . (string) __(
                '[常见误解] 后台将「DNS 托管」选为 Cloudflare 仅表示通过 Cloudflare API 写记录；若权威 NS 仍为 Gname 高防（*.share-dns.com / *.share-dns.net）等而非 Cloudflare 的 *.ns.cloudflare.com，则 CA 从全球递归查询看到的是注册商侧权威区，查不到写在 Cloudflare 上的 TXT，DNS-01 仍会失败。请在注册商处将 NS 整站改为 Cloudflare 给出的两条 NS，并等待传播；或 80 已指向本机时改用 HTTP-01。'
            );
        }
        if ($trustRegistrarWhenPublicMismatch && $domain !== null && (int) $domain->getAccountId() > 0) {
            $suffix .= ' ' . (string) __(
                '[提示] 已尝试通过注册商账户（account_id）API 读取登记 NS，与目标仍不一致或接口不可用；请以注册商控制台为准核对委派 NS。'
            );
        }

        return [
            'ok' => false,
            'message' => (string) __(
                '域名「%{1}」当前权威 NS 为「%{2}」（%{3}），未托管在「%{4}」。向 %{4} API 写入的验证 TXT 不会出现在当前权威 DNS 区，全球解析也查不到，DNS-01 证书必败。%{5}',
                [$rootDomain, $detName, $nsStr, $expName, $suffix]
            ),
            'live_ns' => $live,
            'detected' => $detected,
        ];
    }

    /**
     * 轮询直到本机/DoH 观测的公网 NS 与目标供应商一致（依赖 {@see getLiveNameservers} 与可选 Cloudflare DoH）。
     *
     * @deprecated 证书 DNS-01 已改为 {@see validateAcmeDns01HostingViaAdapters}（仅注册商 + DNS 托管适配器 API），不再调用本方法。
     *
     * @param callable|null $onProgress fn(string $message, array $data): void
     * @return array{ok: bool, message: string, waited_seconds: int, live_ns: array<string>, detected: string, via?: string}
     */
    public function waitForPublicAuthoritativeNsMatchesProvider(
        string $rootDomain,
        string $providerCode,
        int $maxWaitSeconds,
        int $intervalSeconds,
        bool $probeCloudflareDoh,
        ?callable $onProgress = null,
        bool $allowDohToSatisfyWait = true
    ): array {
        $rootDomain = \strtolower(\trim($rootDomain));
        $providerCode = \strtolower(\trim($providerCode));
        if ($rootDomain === '' || $providerCode === '') {
            return [
                'ok' => false,
                'message' => (string) __('参数无效'),
                'waited_seconds' => 0,
                'live_ns' => [],
                'detected' => 'unknown',
            ];
        }
        $maxWaitSeconds = \max(0, $maxWaitSeconds);
        $intervalSeconds = \max(1, $intervalSeconds);
        $elapsed = 0;

        while ($elapsed <= $maxWaitSeconds) {
            $live = $this->getLiveNameservers($rootDomain);
            $detected = $this->dnsDetector->detectProvider($live);
            if ($detected === $providerCode) {
                return [
                    'ok' => true,
                    'message' => '',
                    'waited_seconds' => $elapsed,
                    'live_ns' => $live,
                    'detected' => $detected,
                    'via' => 'resolver',
                ];
            }

            $dohNs = [];
            $dohDet = 'unknown';
            if ($probeCloudflareDoh) {
                $dohNs = $this->getLiveNameserversViaCloudflareDoH($rootDomain);
                $dohDet = $dohNs !== [] ? $this->dnsDetector->detectProvider($dohNs) : 'unknown';
                if ($allowDohToSatisfyWait && $dohDet === $providerCode) {
                    return [
                        'ok' => true,
                        'message' => '',
                        'waited_seconds' => $elapsed,
                        'live_ns' => $dohNs,
                        'detected' => $dohDet,
                        'via' => 'doh',
                    ];
                }
            }

            if ($elapsed >= $maxWaitSeconds) {
                break;
            }

            if ($onProgress !== null) {
                $detName = $this->dnsDetector->getProviderDisplayName($detected);
                $nsStr = $live !== [] ? \implode(', ', $live) : (string) __('(暂无)');
                $expName = $this->dnsDetector->getProviderDisplayName($providerCode);
                $tickMsg = (string) __(
                    '[证书 DNS-01] 等待公网 NS 传播：已等待 %{1} 秒，当前为「%{2}」（%{3}），目标「%{4}」…',
                    [(string) $elapsed, $detName, $nsStr, $expName]
                );
                if (!$allowDohToSatisfyWait && $probeCloudflareDoh && $dohDet === $providerCode && $detected !== $providerCode) {
                    $tickMsg .= ' ' . (string) __(
                        '（DoH 已观测到「%{1}」，本机解析器仍为「%{2}」；须本机也解析到目标后再写 TXT，与 CA 可见性更一致。）',
                        [$expName, $detName]
                    );
                }
                $onProgress($tickMsg, [
                    'step' => 'acme_wait_ns_tick',
                    'elapsed' => $elapsed,
                    'live_ns' => $live,
                    'detected' => $detected,
                    'doh_detected' => $dohDet,
                    'doh_ns' => $dohNs,
                ]);
            }

            $sleep = \min($intervalSeconds, $maxWaitSeconds - $elapsed);
            if ($sleep <= 0) {
                break;
            }
            \sleep($sleep);
            $elapsed += $sleep;
        }

        $live = $this->getLiveNameservers($rootDomain);
        $detected = $this->dnsDetector->detectProvider($live);
        $detName = $this->dnsDetector->getProviderDisplayName($detected);
        $nsStr = $live !== [] ? \implode(', ', $live) : (string) __('(暂无)');

        $dohTimeoutSuffix = '';
        if (!$allowDohToSatisfyWait && $probeCloudflareDoh) {
            $dohNsT = $this->getLiveNameserversViaCloudflareDoH($rootDomain);
            $dohDetT = $dohNsT !== [] ? $this->dnsDetector->detectProvider($dohNsT) : 'unknown';
            if ($dohDetT === $providerCode && $detected !== $providerCode) {
                $dohTimeoutSuffix = ' ' . (string) __(
                    '（Cloudflare DoH 已观测到目标 NS，本机权威查询仍不一致；可检查服务器解析器或延长 env websites.acme_dns.wait_public_ns_max_seconds。）'
                );
            }
        }

        return [
            'ok' => false,
            'message' => (string) __(
                '[证书 DNS-01] 已等待 %{1} 秒，公网权威 NS 仍为「%{2}」（%{3}），与目标「%{4}」不一致。请稍后再试或改用 HTTP-01。%{5}',
                [(string) $elapsed, $detName, $nsStr, $this->dnsDetector->getProviderDisplayName($providerCode), $dohTimeoutSuffix]
            ),
            'waited_seconds' => $elapsed,
            'live_ns' => $live,
            'detected' => $detected,
        ];
    }

    /**
     * @deprecated 请使用 DomainOriginMatchService::fqdnPointsToServer 或 publicAaaaRecordContentMatchesServer
     */
    public function isDomainPointingToServerByRecordContent(string $domain, string $serverIpv4, string $serverIpv6): bool
    {
        return $this->originMatch->publicAaaaRecordContentMatchesServer($domain, $serverIpv4, $serverIpv6);
    }

    /**
     * DNS A 记录查询（返回第一条，兼容其他调用）
     */
    private function resolveA(string $domain): string
    {
        $recordIps = $this->originMatch->collectPublicAaaaRecordIps($domain);
        return $recordIps['ipv4'][0] ?? '';
    }

    /**
     * DNS AAAA 记录查询（返回第一条，兼容其他调用）
     */
    private function resolveAAAA(string $domain): string
    {
        $recordIps = $this->originMatch->collectPublicAaaaRecordIps($domain);
        return $recordIps['ipv6'][0] ?? '';
    }

    /**
     * 加载域名商账户
     */
    private function loadAccount(int $accountId): ?DomainRegistrarAccount
    {
        if ($accountId <= 0) {
            return null;
        }

        $account = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $account->clearQuery()->load($accountId);

        if (!$account->getId()) {
            return null;
        }

        return $account;
    }

    /**
     * 根据服务商代码查找账户
     *
     * @param string $providerCode 服务商代码（如 cloudflare, gname 等）
     * @return DomainRegistrarAccount|null
     */
    public function findAccountByProviderCode(string $providerCode): ?DomainRegistrarAccount
    {
        if ($providerCode === '') {
            return null;
        }

        // 不用 find()->fetch() 承接结果：Query 上若残留 find_fields，fetch 可能返回标量，导致后续 ->getRegistrarCode() 报错
        $regProbe = ObjectManager::getInstance(\Weline\Websites\Model\DomainRegistrar::class);
        $regRows = $regProbe->clearQuery()
            ->where(\Weline\Websites\Model\DomainRegistrar::schema_fields_CODE, \strtolower($providerCode))
            ->where(\Weline\Websites\Model\DomainRegistrar::schema_fields_STATUS, \Weline\Websites\Model\DomainRegistrar::STATUS_ACTIVE)
            ->limit(1)
            ->select()
            ->fetchArray();
        if ($regRows === [] || !\is_array($regRows[0])) {
            return null;
        }
        $registrarId = (int) ($regRows[0][\Weline\Websites\Model\DomainRegistrar::schema_fields_ID] ?? 0);
        if ($registrarId <= 0) {
            return null;
        }

        $accProbe = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $accRows = $accProbe->clearQuery()
            ->where(DomainRegistrarAccount::schema_fields_REGISTRAR_ID, $registrarId)
            ->where(DomainRegistrarAccount::schema_fields_STATUS, DomainRegistrarAccount::STATUS_ACTIVE)
            ->order(DomainRegistrarAccount::schema_fields_ID, 'ASC')
            ->limit(1)
            ->select()
            ->fetchArray();
        if ($accRows === [] || !\is_array($accRows[0])) {
            return null;
        }
        $accountId = (int) ($accRows[0][DomainRegistrarAccount::schema_fields_ID] ?? 0);
        if ($accountId <= 0) {
            return null;
        }

        $account = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $account->clearData(true);
        $account->load($accountId);

        return $account->getAccountId() > 0 ? $account : null;
    }

    /**
     * 为域名池 FQDN 尝试添加指向源站的解析（IPv4→A，IPv6→AAAA；多级子域自动算相对 host）
     *
     * @param DomainPool $pool 域名池模型
     * @param string $serverIp 服务器公网 IPv4 或 IPv6
     * @return array{success: bool, message: string}
     */
    public function tryAddARecordForPoolDomain(DomainPool $pool, string $serverIp): array
    {
        $poolDomain = \strtolower(\trim($pool->getDomain()));
        $rootDomain = \strtolower(\trim((string) $pool->getRootDomain()));
        if ($rootDomain === '') {
            $rootDomain = $poolDomain;
        }
        if ($poolDomain === '' || $rootDomain === '') {
            return [
                'success' => false,
                'message' => (string) __('域名池 FQDN 或根域无效'),
            ];
        }
        if (!\str_ends_with($poolDomain, $rootDomain) && $poolDomain !== $rootDomain) {
            return [
                'success' => false,
                'message' => (string) __('域名池 FQDN 与根域不匹配'),
            ];
        }

        $serverIp = \trim($serverIp);
        if (\filter_var($serverIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $rrType = 'A';
        } elseif (\filter_var($serverIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $rrType = 'AAAA';
        } else {
            return [
                'success' => false,
                'message' => (string) __('无效的服务器 IP（需为合法 IPv4 或 IPv6）'),
            ];
        }

        $rootDomainModel = $this->resolveRootDomainForPool($pool);
        if ($rootDomainModel === null) {
            return [
                'success' => false,
                'message' => (string) __('无法解析根域名的 DNS 管理账户。请确保域名已在「根域名管理」中配置且已关联 DNS 账户。'),
            ];
        }

        $dnsResult = $this->getDnsManagementAccount($rootDomainModel, false);
        if ($dnsResult['error'] !== '' || $dnsResult['adapter'] === null) {
            return [
                'success' => false,
                'message' => $dnsResult['error'] ?: (string) __('未找到 DNS 管理账户'),
            ];
        }

        $host = '@';
        if ($poolDomain !== $rootDomain) {
            $suffix = '.' . $rootDomain;
            if (\str_ends_with($poolDomain, $suffix)) {
                $host = \substr($poolDomain, 0, -\strlen($suffix));
            }
            if ($host === '') {
                $host = '@';
            }
        }

        $record = [
            'type' => $rrType,
            'host' => $host,
            'value' => $serverIp,
            'ttl' => 600,
        ];

        try {
            $result = $dnsResult['adapter']->addDnsRecord($rootDomain, $record, $dnsResult['account']->getCredentials());
            if ($result['success'] ?? false) {
                $msg = $rrType === 'AAAA'
                    ? (string) __('AAAA 记录添加成功，请等待 DNS 生效后重试')
                    : (string) __('A 记录添加成功，请等待 DNS 生效后重试');

                return ['success' => true, 'message' => $msg];
            }
            $fail = $rrType === 'AAAA'
                ? (string) __('添加 AAAA 记录失败')
                : (string) __('添加 A 记录失败');

            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? $fail),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function resolveRootDomainForPool(DomainPool $pool): ?Domain
    {
        $domainModel = ObjectManager::getInstance(Domain::class, [], false);
        $parentId = (int) $pool->getParentDomainId();
        $rootDomain = \trim((string) $pool->getRootDomain());

        if ($parentId > 0) {
            $domainModel->load($parentId);
            if ($domainModel->getDomainId()) {
                return $domainModel;
            }
        }
        if ($rootDomain !== '') {
            $domainModel->clearQuery();
            $domainModel->where(Domain::schema_fields_DOMAIN, $rootDomain)->find()->fetch();
            if ($domainModel->getDomainId()) {
                return $domainModel;
            }
        }
        return null;
    }
}
