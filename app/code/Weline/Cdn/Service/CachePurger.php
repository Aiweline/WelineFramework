<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * 缓存清理服务
 * 
 * @package Weline_Cdn
 */
class CachePurger
{
    private ObjectManager $objectManager;
    private AdapterResolver $adapterResolver;
    private AccountManager $accountManager;
    private FpcPolicyTargetService $targets;

    public function __construct(
        ObjectManager $objectManager,
        AdapterResolver $adapterResolver,
        AccountManager $accountManager,
        FpcPolicyTargetService $targets
    ) {
        $this->objectManager = $objectManager;
        $this->adapterResolver = $adapterResolver;
        $this->accountManager = $accountManager;
        $this->targets = $targets;
    }

    /**
     * 清理缓存
     * 
     * @param string|int $domain 域名ID或名称
     * @param string $mode 清理模式：everything|urls|hosts|tags|cache_keys
     * @param array $data 模式相关的数据
     * @return array ['success' => bool, 'message' => string, ...]
     * @throws Core
     */
    public function purge($domain, string $mode, array $data = []): array
    {
        // 获取域名
        $domainModel = $this->getDomain($domain);
        if (!$domainModel) {
            throw new Core(__('域名不存在：%{1}', [$domain]));
        }

        if (!$domainModel->isEnabled()) {
            throw new Core(__('域名未启用'));
        }

        // 获取适配器
        $adapter = $this->adapterResolver->getAdapter($domainModel->getData(Domain::schema_fields_ADAPTER));
        if (!$adapter) {
            throw new Core(__('适配器不存在：%{1}', [$domainModel->getData(Domain::schema_fields_ADAPTER)]));
        }

        // 获取凭据
        $credentials = $this->getCredentials($domainModel);
        
        $zoneId = $domainModel->getData(Domain::schema_fields_ZONE_ID);
        
        // 根据模式调用不同的清理方法
        switch ($mode) {
            case 'everything':
                return $adapter->purgeEverything($zoneId, $credentials);
                
            case 'urls':
                if (empty($data['urls'])) {
                    throw new Core(__('URL列表不能为空'));
                }
                $urls = is_array($data['urls']) ? $data['urls'] : explode(',', $data['urls']);
                return $adapter->purgeUrls($zoneId, $urls, $credentials);
                
            case 'hosts':
                if (empty($data['hosts'])) {
                    throw new Core(__('Host列表不能为空'));
                }
                $hosts = is_array($data['hosts']) ? $data['hosts'] : explode(',', $data['hosts']);
                return $adapter->purgeHosts($zoneId, $hosts, $credentials);

            case 'prefixes':
                if (!method_exists($adapter, 'purgePrefixes')) {
                    return ['success' => false, 'message' => __('该适配器不支持按 URL 前缀清理')];
                }
                return $adapter->purgePrefixes($zoneId, $data['prefixes'] ?? [], $credentials);
                
            case 'tags':
                if (empty($data['tags'])) {
                    throw new Core(__('Tag列表不能为空'));
                }
                $tags = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
                return $adapter->purgeTags($zoneId, $tags, $credentials);
                
            case 'cache_keys':
                if (empty($data['keys'])) {
                    throw new Core(__('Cache Key列表不能为空'));
                }
                $keys = is_array($data['keys']) ? $data['keys'] : explode(',', $data['keys']);
                return $adapter->purgeCacheKeys($zoneId, $keys, $credentials);
                
            default:
                throw new Core(__('无效的清理模式：%{1}', [$mode]));
        }
    }

    /** A public base URL owns its host or path prefix; it never implies the whole Zone. */
    public function purgePublicScope($domain, string $baseUrl): array
    {
        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $path = (string)parse_url($baseUrl, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return $this->purge($domain, 'hosts', ['hosts' => [$host]]);
        }
        return $this->purge($domain, 'prefixes', ['prefixes' => [$host . $path]]);
    }

    /**
     * 清理该 CDN 域名下属于其站点的全部公网入口（站点 URL、店铺 URL、站点绑定域名、绑定根域别名），
     * 外加调用方给出的同区域 base。无路径 base 合并为一次 hosts，带路径 base 合并为一次 prefixes。
     *
     * @param list<string> $extraBaseUrls
     * @return list<array<string,mixed>> 每次适配器调用的结果
     */
    public function purgeDomainPublicScope(Domain $domain, array $extraBaseUrls = []): array
    {
        $domainId = (int)$domain->getData(Domain::schema_fields_DOMAIN_ID);
        $bound = strtolower(rtrim(trim((string)$domain->getData(Domain::schema_fields_DOMAIN_NAME)), '.'));
        if ($domainId < 1 || $bound === '') {
            return [];
        }
        $bases = [];
        foreach ($extraBaseUrls as $baseUrl) {
            $host = strtolower(rtrim((string)parse_url(trim((string)$baseUrl), PHP_URL_HOST), '.'));
            if ($host === $bound || str_ends_with($host, '.' . $bound)) {
                $bases[] = (string)$baseUrl;
            }
        }
        foreach ($this->targets->publicEndpoints($domain) as $endpoint) {
            $bases[] = $endpoint['base_url'];
        }
        $hosts = [];
        $prefixes = [];
        foreach ($bases as $baseUrl) {
            $host = strtolower(rtrim((string)parse_url($baseUrl, PHP_URL_HOST), '.'));
            if ($host === '') {
                continue;
            }
            $path = rtrim((string)parse_url($baseUrl, PHP_URL_PATH), '/');
            if ($path === '') {
                $hosts[$host] = $host;
            } else {
                $prefixes[$host . $path] = $host;
            }
        }
        foreach ($prefixes as $prefix => $host) {
            if (isset($hosts[$host])) {
                unset($prefixes[$prefix]);
            }
        }
        $results = [];
        if ($hosts !== []) {
            $results[] = $this->purge($domainId, 'hosts', ['hosts' => array_values($hosts)]);
        }
        if ($prefixes !== []) {
            $results[] = $this->purge($domainId, 'prefixes', ['prefixes' => array_keys($prefixes)]);
        }

        return $results;
    }

    /**
     * Website 保存触发的 CDN 清理入口。
     * 已绑定域名时清该站全部公网入口与变更前后的站点 URL；未绑定域名时仍返回可观测的 triggered（本机闭环用）。
     *
     * @param list<string> $urls
     * @return array{success:bool,triggered:bool,skipped?:bool,message?:string,data?:mixed}
     */
    public function purgeWebsiteScope(int $websiteId, array $urls = []): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数'));
        }
        /** @var Domain $domainModel */
        $domainModel = clone $this->objectManager->getInstance(Domain::class);
        $domains = $domainModel->reset()
            ->where(Domain::schema_fields_SITE_ID, $websiteId)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        $normalized = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $normalized[$url] = $url;
            }
        }
        $normalized = array_values($normalized);
        if ($domains === []) {
            return [
                'success' => true,
                'triggered' => true,
                'skipped' => true,
                'message' => (string)__('网站未绑定 CDN 域名，已记录清理意图'),
                'data' => ['website_id' => $websiteId, 'urls' => $normalized],
            ];
        }
        $results = [];
        foreach ($domains as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }
            foreach ($this->purgeDomainPublicScope($domain, $normalized) as $result) {
                $results[] = $result;
            }
        }
        $success = $results !== [];
        $failures = [];
        foreach ($results as $result) {
            if (($result['success'] ?? false) !== true) {
                $success = false;
                $failures[] = (string)($result['message'] ?? '');
            }
        }

        return [
            'success' => $success,
            'triggered' => true,
            'message' => $success
                ? (string)__('缓存清理成功')
                : (string)__('缓存清理失败') . ($failures !== [] ? '：' . implode('；', $failures) : ''),
            'data' => $results,
        ];
    }

    /**
     * 获取域名模型
     * 
     * @param string|int $domain 域名ID或名称
     * @return Domain|null
     */
    private function getDomain($domain): ?Domain
    {
        /** @var Domain $domainModel */
        $domainModel = clone $this->objectManager->getInstance(Domain::class);
        
        if (is_numeric($domain)) {
            $domainModel->reset()->load((int)$domain);
        } else {
            $domainModel->reset()->where(Domain::schema_fields_DOMAIN_NAME, $domain)->find()->fetch();
        }
        
        return $domainModel->getData(Domain::schema_fields_DOMAIN_ID) ? $domainModel : null;
    }

    /**
     * 获取凭据
     * 
     * @param Domain $domain 域名模型
     * @return array
     * @throws Core
     */
    private function getCredentials(Domain $domain): array
    {
        // 如果域名有自定义凭据，使用自定义凭据
        if (!$domain->isInheritDefault() && !empty($domain->getCredentialsArray())) {
            return $domain->getCredentialsArray();
        }

        // 否则使用账户凭据
        $accountId = $domain->isInheritDefault() ? null : $domain->getData(Domain::schema_fields_ACCOUNT_ID);
        if (!$accountId) {
            // 如果没有账户ID，尝试获取默认账户
            $defaultAccount = $this->accountManager->getDefaultAccount($domain->getData(Domain::schema_fields_ADAPTER));
            if ($defaultAccount) {
                return $defaultAccount->getCredentialsArray();
            }
            
            throw new Core(__('域名未配置账户且无默认账户'));
        }

        /** @var Account $account */
        $account = (clone $this->objectManager->getInstance(Account::class))->reset()->load($accountId);
        
        if (!$account->getData(Account::schema_fields_ACCOUNT_ID)) {
            throw new Core(__('账户不存在'));
        }

        if (!$account->isActive()) {
            throw new Core(__('账户未激活'));
        }
        if ($account->getData(Account::schema_fields_ADAPTER) !== $domain->getData(Domain::schema_fields_ADAPTER)) {
            throw new Core(__('账户适配器与域名不匹配'));
        }

        return $account->getCredentialsArray();
    }
}
