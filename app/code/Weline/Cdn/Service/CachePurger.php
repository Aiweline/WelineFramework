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
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

/**
 * 缓存清理服务
 * 
 * 提供多域名、多模式的缓存清理功能
 */
class CachePurger
{
    /**
     * @var AdapterResolver
     */
    private AdapterResolver $adapterResolver;

    /**
     * @var Domain
     */
    private Domain $domainModel;

    /**
     * @var AccountManager
     */
    private AccountManager $accountManager;

    /**
     * 构造函数
     */
    public function __construct(
        AdapterResolver $adapterResolver,
        Domain $domainModel,
        AccountManager $accountManager
    ) {
        $this->adapterResolver = $adapterResolver;
        $this->domainModel = $domainModel;
        $this->accountManager = $accountManager;
    }

    /**
     * @DESC          # 清理指定域名的所有缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName 域名ID或域名名称
     * @return array ['success' => bool, 'message' => string]
     */
    public function purgeDomain(int|string $domainIdOrName): array
    {
        $domain = $this->loadDomain($domainIdOrName);
        if (!$domain || !$domain->getId()) {
            return [
                'success' => false,
                'message' => __('域名不存在')
            ];
        }

        if (!$domain->isEnabled()) {
            return [
                'success' => false,
                'message' => __('域名未启用')
            ];
        }

        $adapterCode = $domain->getData(Domain::fields_ADAPTER);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);

        if (empty($adapterCode) || empty($zoneId)) {
            return [
                'success' => false,
                'message' => __('域名配置不完整')
            ];
        }

        $adapter = $this->adapterResolver->get($adapterCode);
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('适配器不存在: %{1}', [$adapterCode])
            ];
        }

        $credentials = $this->resolveCredentials($domain);
        if (empty($credentials)) {
            return [
                'success' => false,
                'message' => __('域名未配置有效的凭据')
            ];
        }

        try {
            $result = $adapter->purgeEverything($zoneId, $credentials);
            return [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? __('清理完成')
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * @DESC          # 按URL清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName 域名ID或域名名称
     * @param array $urls URL数组
     * @return array
     */
    public function purgeUrls(int|string $domainIdOrName, array $urls): array
    {
        $domain = $this->loadDomain($domainIdOrName);
        if (!$domain || !$domain->getId()) {
            return [
                'success' => false,
                'message' => __('域名不存在')
            ];
        }

        $adapterCode = $domain->getData(Domain::fields_ADAPTER);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        $credentials = $this->resolveCredentials($domain);

        if (empty($adapterCode) || empty($zoneId) || empty($credentials)) {
            return [
                'success' => false,
                'message' => __('域名配置不完整')
            ];
        }

        $adapter = $this->adapterResolver->get($adapterCode);
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('适配器不存在')
            ];
        }

        try {
            return $adapter->purgeUrls($zoneId, $urls, $credentials);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * @DESC          # 按Host清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName 域名ID或域名名称
     * @param array $hosts Host数组
     * @return array
     */
    public function purgeHosts(int|string $domainIdOrName, array $hosts): array
    {
        $domain = $this->loadDomain($domainIdOrName);
        if (!$domain || !$domain->getId()) {
            return [
                'success' => false,
                'message' => __('域名不存在')
            ];
        }

        $adapterCode = $domain->getData(Domain::fields_ADAPTER);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        $credentials = $this->resolveCredentials($domain);

        if (empty($adapterCode) || empty($zoneId) || empty($credentials)) {
            return [
                'success' => false,
                'message' => __('域名配置不完整')
            ];
        }

        $adapter = $this->adapterResolver->get($adapterCode);
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('适配器不存在')
            ];
        }

        try {
            return $adapter->purgeHosts($zoneId, $hosts, $credentials);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * @DESC          # 按Tag清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName 域名ID或域名名称
     * @param array $tags Tag数组
     * @return array
     */
    public function purgeTags(int|string $domainIdOrName, array $tags): array
    {
        $domain = $this->loadDomain($domainIdOrName);
        if (!$domain || !$domain->getId()) {
            return [
                'success' => false,
                'message' => __('域名不存在')
            ];
        }

        $adapterCode = $domain->getData(Domain::fields_ADAPTER);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        $credentials = $this->resolveCredentials($domain);

        if (empty($adapterCode) || empty($zoneId) || empty($credentials)) {
            return [
                'success' => false,
                'message' => __('域名配置不完整')
            ];
        }

        $adapter = $this->adapterResolver->get($adapterCode);
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('适配器不存在')
            ];
        }

        try {
            return $adapter->purgeTags($zoneId, $tags, $credentials);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * @DESC          # 按Cache Key清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName 域名ID或域名名称
     * @param array $keys Cache Key数组
     * @return array
     */
    public function purgeCacheKeys(int|string $domainIdOrName, array $keys): array
    {
        $domain = $this->loadDomain($domainIdOrName);
        if (!$domain || !$domain->getId()) {
            return [
                'success' => false,
                'message' => __('域名不存在')
            ];
        }

        $adapterCode = $domain->getData(Domain::fields_ADAPTER);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        $credentials = $this->resolveCredentials($domain);

        if (empty($adapterCode) || empty($zoneId) || empty($credentials)) {
            return [
                'success' => false,
                'message' => __('域名配置不完整')
            ];
        }

        $adapter = $this->adapterResolver->get($adapterCode);
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('适配器不存在')
            ];
        }

        try {
            return $adapter->purgeCacheKeys($zoneId, $keys, $credentials);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * @DESC          # 加载域名（支持ID或名称）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param int|string $domainIdOrName
     * @return Domain|null
     */
    private function loadDomain(int|string $domainIdOrName): ?Domain
    {
        try {
            /** @var Domain $domain */
            $domain = $this->domainModel->clear()->reset();
            
            if (is_numeric($domainIdOrName)) {
                $domain->load((int)$domainIdOrName);
            } else {
                $domain->where(Domain::fields_DOMAIN_NAME, $domainIdOrName)->find()->fetch();
            }
            
            return $domain->getId() ? $domain : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @DESC          # 解析域名凭据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param Domain $domain
     * @return array
     */
    private function resolveCredentials(Domain $domain): array
    {
        // 1. 自定义凭据
        $customCredentials = $domain->getCredentials();
        if (!empty($customCredentials)) {
            return $customCredentials;
        }

        // 2. 指定账户凭据
        $accountId = $domain->getData(Domain::fields_ACCOUNT_ID);
        if ($accountId) {
            try {
                /** @var Account $account */
                $account = ObjectManager::getInstance(Account::class)->clear()->reset()->load($accountId);
                if ($account->getId()) {
                    $accountCredentials = $account->getCredentials();
                    if (!empty($accountCredentials)) {
                        return $accountCredentials;
                    }
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }

        // 3. 默认账户凭据
        $adapter = $domain->getData(Domain::fields_ADAPTER);
        if ($adapter && ($domain->getData(Domain::fields_INHERIT_DEFAULT) ?? true)) {
            $defaultAccount = $this->accountManager->getDefaultAccount($adapter);
            if ($defaultAccount) {
                $defaultCredentials = $defaultAccount->getCredentials();
                if (!empty($defaultCredentials)) {
                    return $defaultCredentials;
                }
            }
        }

        return [];
    }
}
