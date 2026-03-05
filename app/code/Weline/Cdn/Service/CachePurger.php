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

    public function __construct(
        ObjectManager $objectManager,
        AdapterResolver $adapterResolver,
        AccountManager $accountManager
    ) {
        $this->objectManager = $objectManager;
        $this->adapterResolver = $adapterResolver;
        $this->accountManager = $accountManager;
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

    /**
     * 获取域名模型
     * 
     * @param string|int $domain 域名ID或名称
     * @return Domain|null
     */
    private function getDomain($domain): ?Domain
    {
        /** @var Domain $domainModel */
        $domainModel = $this->objectManager->getInstance(Domain::class);
        
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
        $accountId = $domain->getData(Domain::schema_fields_ACCOUNT_ID);
        if (!$accountId) {
            // 如果没有账户ID，尝试获取默认账户
            $defaultAccount = $this->accountManager->getDefaultAccount($domain->getData(Domain::schema_fields_ADAPTER));
            if ($defaultAccount) {
                return $defaultAccount->getCredentialsArray();
            }
            
            throw new Core(__('域名未配置账户且无默认账户'));
        }

        /** @var Account $account */
        $account = $this->objectManager->getInstance(Account::class)->reset()->load($accountId);
        
        if (!$account->getData(Account::schema_fields_ACCOUNT_ID)) {
            throw new Core(__('账户不存在'));
        }

        if (!$account->isActive()) {
            throw new Core(__('账户未激活'));
        }

        return $account->getCredentialsArray();
    }
}

