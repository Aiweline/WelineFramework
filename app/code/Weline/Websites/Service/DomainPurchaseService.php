<?php
declare(strict_types=1);

/**
 * 域名购买服务
 *
 * 处理批量域名购买逻辑：
 * - 创建购买订单
 * - 逐个调用适配器 API 购买域名
 * - 购买成功后自动入域名池
 * - 可选绑定站点或自动新建站点
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainAutoResolveTask;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPurchaseItem;
use Weline\Websites\Model\DomainPurchaseOrder;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

class DomainPurchaseService
{
    private DomainPurchaseOrder $orderModel;
    private DomainPurchaseItem $itemModel;
    private DomainRegistrar $registrarModel;
    private DomainRegistrarAccount $accountModel;
    private DomainRegistrarResolverService $resolverService;
    private DomainPool $domainPool;
    private EventsManager $eventsManager;

    public function __construct(
        DomainPurchaseOrder $orderModel,
        DomainPurchaseItem $itemModel,
        DomainRegistrar $registrarModel,
        DomainRegistrarAccount $accountModel,
        DomainRegistrarResolverService $resolverService,
        DomainPool $domainPool,
        EventsManager $eventsManager
    ) {
        $this->orderModel = $orderModel;
        $this->itemModel = $itemModel;
        $this->registrarModel = $registrarModel;
        $this->accountModel = $accountModel;
        $this->resolverService = $resolverService;
        $this->domainPool = $domainPool;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 创建并处理购买订单
     *
     * @param int $accountId 域名商账号 ID
     * @param array $items 购买条目列表 [{domain, years, website_id, auto_create_site, resolve_to_local?, subdomains?}, ...]
     *   resolve_to_local: yes|no 是否解析到本服务器（默认 yes）
     *   subdomains: array 解析子域，默认 ['@','www']
     * @param bool $autoResolve 是否自动解析到本服务器（兼容旧调用，若 items 含 resolve_to_local 则以 items 为准）
     * @return array{success: bool, message: string, order_id?: int, order_no?: string, results?: array}
     */
    public function createAndProcessOrder(int $accountId, array $items, bool $autoResolve = false): array
    {
        // 如果 items 是 JSON 字符串（从前端 FormData 传来），解码
        if (\is_string($items)) {
            $decoded = \json_decode($items, true);
            if (\is_array($decoded)) {
                $items = $decoded;
            }
        }

        if (empty($items)) {
            return [
                'success' => false,
                'message' => __('购买条目不能为空'),
            ];
        }

        $skippedEmpty = 0;
        $skippedMalformed = 0;
        $items = \array_values(\array_filter(
            $items,
            static function (mixed $row) use (&$skippedEmpty, &$skippedMalformed): bool {
                if (!\is_array($row)) {
                    ++$skippedMalformed;

                    return false;
                }
                $d = \strtolower(\trim((string) ($row['domain'] ?? '')));
                if ($d === '') {
                    ++$skippedEmpty;

                    return false;
                }

                return true;
            }
        ));
        if ($items === []) {
            $parts = [];
            if ($skippedEmpty > 0) {
                $parts[] = __('空域名 %{n} 条', ['n' => $skippedEmpty]);
            }
            if ($skippedMalformed > 0) {
                $parts[] = __('无效条目 %{n} 条（非对象）', ['n' => $skippedMalformed]);
            }

            return [
                'success' => false,
                'message' => $parts !== []
                    ? __('没有有效的域名（已跳过：%{detail}）', ['detail' => \implode('，', $parts)])
                    : __('没有有效的域名'),
            ];
        }

        $defaultPc = Env::module_env('Weline_Websites', 'domain_purchase_default_contact');
        if (\is_array($defaultPc) && $defaultPc !== []) {
            foreach ($items as &$row) {
                $existing = [];
                if (isset($row['purchase_contact'])) {
                    $raw = $row['purchase_contact'];
                    $existing = \is_string($raw) ? (\json_decode($raw, true) ?: []) : (array) $raw;
                }
                $row['purchase_contact'] = \array_merge($defaultPc, $existing);
            }
            unset($row);
        }

        // 获取账号和适配器
        $account = clone $this->accountModel;
        $account->load($accountId);
        if (!$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('域名商账号不存在'),
            ];
        }

        $registrar = clone $this->registrarModel;
        $registrar->load($account->getRegistrarId());
        $adapter = $this->resolverService->getAdapter($registrar->getCode());
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('未找到对应的域名商适配器'),
            ];
        }

        $credentials = $account->getCredentials();

        // 创建订单
        $order = clone $this->orderModel;
        $order->setData(DomainPurchaseOrder::schema_fields_ACCOUNT_ID, $accountId);
        $order->setData(DomainPurchaseOrder::schema_fields_TOTAL_COUNT, \count($items));
        $order->setData(DomainPurchaseOrder::schema_fields_STATUS, DomainPurchaseOrder::STATUS_PROCESSING);
        $order->save();

        $orderId = $order->getOrderId();
        $successCount = 0;
        $failCount = 0;
        $lifecycleFailCount = 0;
        $results = [];

        // 逐个处理购买条目
        foreach ($items as $itemData) {
            $domain = \strtolower(\trim($itemData['domain'] ?? ''));
            $years = \max(1, \min(10, (int) ($itemData['years'] ?? 1)));
            $websiteId = (int) ($itemData['website_id'] ?? 0);
            $autoCreateSite = ($itemData['auto_create_site'] ?? 'no') === 'yes' ? 'yes' : 'no';
            $resolveToLocal = isset($itemData['resolve_to_local'])
                ? (($itemData['resolve_to_local'] ?? 'yes') === 'yes')
                : $autoResolve;
            $dnsChoice = (string) ($itemData['dns_choice'] ?? 'follow_registrar');
            $dnsProvider = \strtolower(\trim((string) ($itemData['dns_provider'] ?? '')));
            $dnsAccountId = (int) ($itemData['dns_account_id'] ?? 0);
            $dnsNameservers = \trim((string) ($itemData['dns_nameservers'] ?? ''));
            $cdnChoice = (string) ($itemData['cdn_choice'] ?? 'follow_registrar');
            $cdnProvider = \strtolower(\trim((string) ($itemData['cdn_provider'] ?? '')));
            $cdnAccountId = (int) ($itemData['cdn_account_id'] ?? 0);
            $startLifecycle = !isset($itemData['start_lifecycle'])
                || !\in_array((string) $itemData['start_lifecycle'], ['0', 'false', 'no'], true);
            $subdomains = $itemData['subdomains'] ?? ['@', 'www'];
            $subdomains = $this->normalizeSubdomains($subdomains);

            // 创建购买条目
            $item = clone $this->itemModel;
            $item->setData(DomainPurchaseItem::schema_fields_ORDER_ID, $orderId);
            $item->setData(DomainPurchaseItem::schema_fields_DOMAIN, $domain);
            $item->setData(DomainPurchaseItem::schema_fields_YEARS, $years);
            $item->setData(DomainPurchaseItem::schema_fields_WEBSITE_ID, $websiteId);
            $item->setData(DomainPurchaseItem::schema_fields_AUTO_CREATE_SITE, $autoCreateSite);
            $item->setData(DomainPurchaseItem::schema_fields_STATUS, DomainPurchaseItem::STATUS_PENDING);

            $lifecycleResult = [
                'attempted' => false,
                'success' => false,
                'order_id' => 0,
                'message' => '',
            ];
            try {
                $contactInfo = $this->buildAdapterContactInfo($itemData, $dnsChoice, $dnsNameservers);
                $purchaseResult = $adapter->purchaseDomain($domain, $years, $credentials, $contactInfo);

                if ($purchaseResult['success'] ?? false) {
                    $lifecycleResult = [
                        'attempted' => false,
                        'success' => true,
                        'order_id' => 0,
                        'message' => '',
                    ];
                    $item->setData(DomainPurchaseItem::schema_fields_STATUS, DomainPurchaseItem::STATUS_SUCCESS);
                    $item->setData(DomainPurchaseItem::schema_fields_PRICE, $purchaseResult['price'] ?? 0);
                    $item->setData(DomainPurchaseItem::schema_fields_CURRENCY, $purchaseResult['currency'] ?? 'USD');
                    $successCount++;

                    // 购买成功后入域名池（含 @、www 等子域）
                    $rootDomain = $this->addToDomainPoolWithSubdomains($domain, $accountId, $subdomains);
                    $this->persistPurchasedDomainDnsMetadata(
                        $domain,
                        $accountId,
                        (string)$registrar->getCode(),
                        $purchaseResult,
                        $dnsChoice,
                        $dnsNameservers,
                        $dnsProvider,
                        $dnsAccountId,
                        $cdnChoice,
                        $cdnProvider,
                        $cdnAccountId,
                        $rootDomain
                    );

                    // 绑定站点
                    if ($websiteId > 0 || $autoCreateSite === 'yes') {
                        $this->bindToWebsite($domain, $websiteId, $autoCreateSite);
                    }

                    // 如果开启解析到本站，创建解析任务
                    if ($resolveToLocal) {
                        $this->createAutoResolveTask($domain, $accountId);
                    }

                    if ($startLifecycle) {
                        $lifecycleResult = $this->startLifecycleTracking($domain, $accountId, [
                            'years' => $years,
                            'website_id' => $websiteId,
                            'auto_create_site' => $autoCreateSite,
                            'resolve_to_local' => $resolveToLocal,
                            'subdomains' => $subdomains,
                            'dns_choice' => $dnsChoice,
                            'dns_provider' => $dnsProvider,
                            'dns_account_id' => $dnsAccountId,
                            'dns_nameservers' => $dnsNameservers,
                            'cdn_choice' => $cdnChoice,
                            'cdn_provider' => $cdnProvider,
                            'cdn_account_id' => $cdnAccountId,
                        ]);
                        if (!($lifecycleResult['success'] ?? false)) {
                            $lifecycleFailCount++;
                        }
                    }

                    // 触发购买成功事件
                    $eventData = [
                        'data' => [
                            'domain' => $domain,
                            'order_id' => $orderId,
                            'account_id' => $accountId,
                            'website_id' => $websiteId,
                            'auto_create_site' => $autoCreateSite,
                            'resolve_to_local' => $resolveToLocal,
                            'subdomains' => $subdomains,
                            'dns_choice' => $dnsChoice,
                            'dns_provider' => $dnsProvider,
                            'dns_account_id' => $dnsAccountId,
                            'dns_nameservers' => $dnsNameservers,
                            'cdn_choice' => $cdnChoice,
                            'cdn_provider' => $cdnProvider,
                            'cdn_account_id' => $cdnAccountId,
                            'start_lifecycle' => $startLifecycle,
                            'lifecycle_result' => $lifecycleResult,
                        ],
                    ];
                    $this->eventsManager->dispatch('Weline_Websites::domain::purchase_success', $eventData);
                    if ($startLifecycle && !($lifecycleResult['success'] ?? false)) {
                        $verifiedLifecycleResult = $this->getLifecycleTrackingStatus($domain);
                        if ($verifiedLifecycleResult['success']) {
                            $lifecycleResult = $verifiedLifecycleResult;
                            $lifecycleFailCount--;
                        }
                    }
                } else {
                    $item->setData(DomainPurchaseItem::schema_fields_STATUS, DomainPurchaseItem::STATUS_FAILED);
                    $item->setData(DomainPurchaseItem::schema_fields_ERROR_MESSAGE, $purchaseResult['message'] ?? __('购买失败'));
                    $failCount++;
                }

                $results[] = [
                    'domain' => $domain,
                    'success' => $purchaseResult['success'] ?? false,
                    'message' => $purchaseResult['message'] ?? '',
                    'lifecycle' => $lifecycleResult ?? [
                        'attempted' => false,
                        'success' => false,
                        'order_id' => 0,
                        'message' => '',
                    ],
                ];
            } catch (\Exception $e) {
                $item->setData(DomainPurchaseItem::schema_fields_STATUS, DomainPurchaseItem::STATUS_FAILED);
                $item->setData(DomainPurchaseItem::schema_fields_ERROR_MESSAGE, $e->getMessage());
                $failCount++;

                $results[] = [
                    'domain' => $domain,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }

            $item->save();
        }

        // 更新订单状态
        $order->setData(DomainPurchaseOrder::schema_fields_SUCCESS_COUNT, $successCount);
        $order->setData(DomainPurchaseOrder::schema_fields_FAIL_COUNT, $failCount);
        $order->setData(DomainPurchaseOrder::schema_fields_STATUS,
            $failCount === 0 ? DomainPurchaseOrder::STATUS_COMPLETED : (
                $successCount === 0 ? DomainPurchaseOrder::STATUS_FAILED : DomainPurchaseOrder::STATUS_COMPLETED
            )
        );
        $order->save();

        $message = __('购买完成：%{success} 成功，%{fail} 失败', [
            'success' => $successCount,
            'fail' => $failCount,
        ]);
        if ($lifecycleFailCount > 0) {
            $message = __('%{message}；其中 %{count} 个域名未成功创建生命周期订单，请检查生命周期模块或根据返回结果重试。', [
                'message' => $message,
                'count' => $lifecycleFailCount,
            ]);
        }
        if ($skippedEmpty > 0 || $skippedMalformed > 0) {
            $skipMsg = [];
            if ($skippedEmpty > 0) {
                $skipMsg[] = __('空 %{n} 条', ['n' => $skippedEmpty]);
            }
            if ($skippedMalformed > 0) {
                $skipMsg[] = __('无效结构 %{n} 条', ['n' => $skippedMalformed]);
            }
            $message = __('%{message}（提交时已忽略：%{detail}）', [
                'message' => $message,
                'detail' => \implode('，', $skipMsg),
            ]);
        }

        return [
            'success' => $successCount > 0,
            'message' => $message,
            'order_id' => $orderId,
            'order_no' => $order->getOrderNo(),
            'results' => $results,
        ];
    }

    /**
     * 合并自定义 NS、Gname 模板/溢价、各注册商联系人（purchase_contact 与条目级字段）。
     *
     * @param array<string, mixed> $itemData
     * @return array<string, mixed>
     */
    private function buildAdapterContactInfo(array $itemData, string $dnsChoice, string $dnsNameservers): array
    {
        $info = [];
        if ($dnsChoice === 'custom_nameservers' && $dnsNameservers !== '') {
            $info['dns'] = $dnsNameservers;
        }
        $pc = $itemData['purchase_contact'] ?? [];
        if (\is_string($pc)) {
            $decoded = \json_decode($pc, true);
            $pc = \is_array($decoded) ? $decoded : [];
        }
        $flat = \is_array($pc) ? $pc : [];
        $mergeKeys = [
            'first_name', 'last_name', 'email', 'phone', 'address1', 'city', 'state',
            'postal_code', 'country', 'organization', 'privacy',
            'gname_template_id', 'template_id', 'premium_amount',
        ];
        foreach ($mergeKeys as $k) {
            if (isset($itemData[$k]) && $itemData[$k] !== '' && $itemData[$k] !== null) {
                $flat[$k] = $itemData[$k];
            }
        }
        if ($flat !== []) {
            $info['purchase_contact_flat'] = $flat;
        }
        $tid = \trim((string) ($itemData['template_id'] ?? $flat['gname_template_id'] ?? $flat['template_id'] ?? ''));
        if ($tid !== '') {
            $info['template_id'] = $tid;
        }
        if (isset($flat['premium_amount']) && (float) $flat['premium_amount'] > 0) {
            $info['premium_amount'] = (float) $flat['premium_amount'];
        }
        if (\array_key_exists('privacy', $flat)) {
            $info['privacy'] = \filter_var($flat['privacy'], FILTER_VALIDATE_BOOLEAN)
                || $flat['privacy'] === '1' || $flat['privacy'] === 1;
        }
        foreach (
            [
                'contact',
                'contactRegistrant',
                'contactAdmin',
                'contactTech',
                'contactBilling',
                'aws_admin_contact',
            ] as $k
        ) {
            if (!empty($itemData[$k]) && \is_array($itemData[$k])) {
                $info[$k] = $itemData[$k];
            }
        }
        if (!empty($itemData['aliyun_contact_json'])) {
            $info['aliyun_contact_json'] = $itemData['aliyun_contact_json'];
        }
        $uip = \trim((string) ($itemData['user_client_ip'] ?? ''));
        if ($uip !== '' && \filter_var($uip, FILTER_VALIDATE_IP)) {
            $info['user_client_ip'] = $uip;
        }

        return $info;
    }

    /**
     * 规范化子域列表，防止 JSON 字符串 '["@","www"]' 被错误处理
     *
     * @param array|string $subdomains 子域列表，可能为数组或 JSON 字符串或逗号分隔字符串
     * @return array<string>
     */
    private function normalizeSubdomains(array|string $subdomains): array
    {
        if (\is_string($subdomains)) {
            $s = \trim($subdomains);
            if (\str_starts_with($s, '[')) {
                $decoded = \json_decode($s, true);
                $subdomains = \is_array($decoded) ? $decoded : \array_map('trim', \explode(',', $s));
            } else {
                $subdomains = \array_map('trim', \explode(',', $s));
            }
        }
        $result = [];
        foreach ((array) $subdomains as $p) {
            if (\is_array($p)) {
                $result = \array_merge($result, $this->normalizeSubdomains($p));
                continue;
            }
            $s = \trim((string) $p);
            if ($s === '' || \str_contains($s, '[') || \str_contains($s, ']')) {
                continue;
            }
            if ($s === '@' || \preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*$/i', $s)) {
                $result[] = $s === '@' ? '@' : \strtolower($s);
            }
        }
        $result = \array_values(\array_unique($result));
        return $result === [] ? ['@', 'www'] : $result;
    }

    /**
     * 将域名及其子域（@、www 等）添加到域名池
     *
     * @param string $domain 根域名
     * @param int $accountId 域名商账号 ID
     * @param array $prefixes 子域前缀，如 ['@','www']
     * @return Domain|null 创建/加载到的根域模型，失败时返回 null
     */
    private function addToDomainPoolWithSubdomains(string $domain, int $accountId, array $prefixes = ['@', 'www']): ?Domain
    {
        try {
            // 确保 Domain 记录存在（供 SubdomainGenerator 使用）
            $domainModel = ObjectManager::getInstance(Domain::class);
            $domainModel->syncDomains($accountId, [
                [
                    'domain' => $domain,
                    'status' => Domain::STATUS_ACTIVE,
                ],
            ]);

            $rootDomain = clone $domainModel;
            $rootDomain->loadByDomainAndAccount($domain, $accountId);
            if ($rootDomain->getDomainId()) {
                $subdomainGenerator = ObjectManager::getInstance(SubdomainGeneratorService::class);
                $subdomainGenerator->generateDefaultSubdomains($rootDomain, $prefixes);
                return $rootDomain;
            }
            // 若无法加载 Domain，则仅添加根域到池
            $this->addToDomainPool($domain);
            return null;
        } catch (\Exception $e) {
            w_log_error(__('域名入池失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
            return null;
        }
    }

    /**
     * 将单个域名添加到域名池
     */
    private function addToDomainPool(string $domain): void
    {
        try {
            $pool = clone $this->domainPool;
            $pool->loadByDomain($domain);
            if (!$pool->getPoolId()) {
                $pool->clearData();
                $pool->setDomain($domain);
                $pool->setStatus(DomainPool::STATUS_ACTIVE);
                $pool->setResolveStatus(DomainPool::RESOLVE_STATUS_PENDING);
                $pool->setDnsStatus(DomainPool::INFRA_STATUS_PENDING);
                $pool->setCdnStatus(DomainPool::INFRA_STATUS_PENDING);
                $pool->setHttpsStatus(DomainPool::HTTPS_STATUS_NONE);
                $pool->setSiteReady(false);
                $pool->save();
            }
        } catch (\Exception $e) {
            w_log_error(__('域名入池失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function persistPurchasedDomainDnsMetadata(
        string $domain,
        int $accountId,
        string $registrarCode,
        array $purchaseResult,
        string $dnsChoice,
        string $dnsNameservers,
        string $selectedDnsProvider,
        int $selectedDnsAccountId,
        string $cdnChoice,
        string $selectedCdnProvider,
        int $selectedCdnAccountId,
        ?Domain $rootDomain = null
    ): void {
        try {
            if ($rootDomain === null || !$rootDomain->getDomainId()) {
                $domainModel = ObjectManager::getInstance(Domain::class);
                $rootDomain = clone $domainModel;
                $rootDomain->loadByDomainAndAccount($domain, $accountId);
            }
            if (!$rootDomain->getDomainId()) {
                return;
            }

            $nameservers = [];
            if (!empty($purchaseResult['nameservers'])) {
                $nameservers = \is_array($purchaseResult['nameservers'])
                    ? $purchaseResult['nameservers']
                    : \array_map('trim', \explode(',', (string)$purchaseResult['nameservers']));
            } elseif ($dnsChoice === 'custom_nameservers' && $dnsNameservers !== '') {
                $nameservers = \array_map('trim', \explode(',', $dnsNameservers));
            }

            $nameservers = \array_values(\array_filter(\array_map(static function ($ns) {
                return \rtrim(\strtolower(\trim((string)$ns)), '.');
            }, $nameservers)));

            $detector = ObjectManager::getInstance(DnsProviderDetector::class);
            $provider = '';
            if ($nameservers !== []) {
                $provider = $detector->detectProvider($nameservers);
                $rootDomain->setNameservers($nameservers);
            }
            if ($provider === '' || $provider === 'unknown') {
                if ($registrarCode !== '') {
                    $provider = \strtolower($registrarCode);
                }
            }

            // 在覆盖前保存当前真实 NS 归属，用于后续判断是否需要切换
            $currentNsProvider = ($provider !== '' && $provider !== 'unknown') ? $provider : \strtolower($registrarCode);

            if ($dnsChoice === 'provider_account') {
                if ($selectedDnsProvider !== '') {
                    $provider = $selectedDnsProvider;
                }
                if ($selectedDnsAccountId > 0) {
                    $rootDomain->setDnsAccountId($selectedDnsAccountId);
                }
            }
            if ($provider !== '' && $provider !== 'unknown') {
                $rootDomain->setDnsProvider($provider);
            }

            if ($cdnChoice === 'provider_account') {
                if ($selectedCdnProvider !== '') {
                    $rootDomain->setCdnProvider($selectedCdnProvider);
                }
                if ($selectedCdnAccountId > 0) {
                    $rootDomain->setCdnAccountId($selectedCdnAccountId);
                }
            }

            $needSwitch = false;
            if ($dnsChoice === 'provider_account' && $selectedDnsProvider !== '' && $selectedDnsAccountId > 0) {
                if ($selectedDnsProvider !== $currentNsProvider) {
                    $needSwitch = true;
                }
            }
            if ($cdnChoice === 'provider_account' && $selectedCdnProvider !== '' && $selectedCdnAccountId > 0) {
                if ($selectedCdnProvider !== $currentNsProvider) {
                    $needSwitch = true;
                }
            }

            // 购买后不立即执行 DNS/CDN 切换：域名可能仍在注册中，等注册完成由定时任务自动处理
            if ($needSwitch) {
                $rootDomain->setDnsSwitchDeferred(1);
            }
            $rootDomain->save();
        } catch (\Throwable $e) {
            w_log_error(__('购买后同步域名 DNS 元数据失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 绑定域名到站点（或自动创建站点）
     */
    private function bindToWebsite(string $domain, int $websiteId, string $autoCreateSite): void
    {
        try {
            if ($autoCreateSite === 'yes' && $websiteId <= 0) {
                // 自动创建站点
                $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                $newSite = clone $websiteModel;
                $newSite->clearData();

                // 用域名前缀作为站点名称和代码
                $parts = \explode('.', $domain);
                $code = $parts[0] ?? $domain;
                $newSite->setData('name', $domain);
                $newSite->setData('code', $code);
                $newSite->setData('url', 'http://' . $domain);
                $newSite->save();

                $websiteId = (int) $newSite->getData('website_id');
            }

            if ($websiteId > 0) {
                // 添加域名到网站
                $websiteDomain = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteDomain::class);
                $domainModel = clone $websiteDomain;
                $domainModel->clearData();
                $domainModel->setData('website_id', $websiteId);
                $domainModel->setData('domain', $domain);
                $domainModel->setData('status', 'active');
                $domainModel->save();
            }
        } catch (\Exception $e) {
            w_log_error(__('域名绑站失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * 创建自动解析任务
     */
    private function createAutoResolveTask(string $domain, int $accountId): void
    {
        try {
            DomainAutoResolveTask::createTask($domain, $accountId);
        } catch (\Exception $e) {
            w_log_error(__('创建自动解析任务失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * 购买完成后启动根域级生命周期跟踪。
     */
    private function startLifecycleTracking(string $domain, int $accountId, array $options): array
    {
        try {
            $serviceClass = \Weline\Websites\Service\DomainLifecycleOrchestrationService::class;
            if (!\class_exists($serviceClass)) {
                return [
                    'attempted' => false,
                    'success' => false,
                    'order_id' => 0,
                    'message' => __('生命周期模块不可用'),
                ];
            }

            $service = ObjectManager::getInstance($serviceClass);
            if (\method_exists($service, 'startPurchasedLifecycle')) {
                $result = $service->startPurchasedLifecycle($domain, $accountId, $options);
                $orderId = (int) ($result['order_id'] ?? 0);
                $success = (bool) ($result['success'] ?? false);

                if ($orderId <= 0 && \method_exists($service, 'getOrderByDomain')) {
                    $order = $service->getOrderByDomain($domain);
                    if ($order && \method_exists($order, 'getOrderId')) {
                        $orderId = (int) $order->getOrderId();
                        $success = $success || $orderId > 0;
                    }
                }

                return [
                    'attempted' => true,
                    'success' => $success && $orderId > 0,
                    'order_id' => $orderId,
                    'message' => (string) ($result['message'] ?? ''),
                ];
            }
            return [
                'attempted' => false,
                'success' => false,
                'order_id' => 0,
                'message' => __('生命周期服务缺少 startPurchasedLifecycle 方法'),
            ];
        } catch (\Throwable $e) {
            w_log_warning(__('启动域名生命周期跟踪失败：%{domain}，错误：%{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
            return [
                'attempted' => true,
                'success' => false,
                'order_id' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function getLifecycleTrackingStatus(string $domain): array
    {
        try {
            $serviceClass = \Weline\Websites\Service\DomainLifecycleOrchestrationService::class;
            if (!\class_exists($serviceClass)) {
                return [
                    'attempted' => false,
                    'success' => false,
                    'order_id' => 0,
                    'message' => __('生命周期模块不可用'),
                ];
            }

            $service = ObjectManager::getInstance($serviceClass);
            if (!\method_exists($service, 'getOrderByDomain')) {
                return [
                    'attempted' => false,
                    'success' => false,
                    'order_id' => 0,
                    'message' => __('生命周期服务缺少 getOrderByDomain 方法'),
                ];
            }

            $order = $service->getOrderByDomain($domain);
            $orderId = ($order && \method_exists($order, 'getOrderId')) ? (int) $order->getOrderId() : 0;

            return [
                'attempted' => true,
                'success' => $orderId > 0,
                'order_id' => $orderId,
                'message' => $orderId > 0 ? __('生命周期订单已创建') : __('未找到生命周期订单'),
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'success' => false,
                'order_id' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }
}
