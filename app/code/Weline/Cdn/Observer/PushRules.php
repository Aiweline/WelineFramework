<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Observer;

use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN规则推送观察者（Cloudflare适配器）
 * 
 * 监听Weline_Cdn::push_rules事件，处理Cloudflare适配器的规则推送
 * 
 * @package Weline_Cdn
 */
class PushRules implements ObserverInterface
{
    private Cloudflare $adapter;

    public function __construct(Cloudflare $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        $rules = $event->getData('rules'); // 通用规则，Cloudflare格式
        $adapterCode = $event->getData('adapter_code');
        $triggerType = $event->getData('trigger_type', 'cron');
        
        // 只处理使用Cloudflare适配器的域名
        if ($adapterCode !== 'cloudflare') {
            return; // 其他适配器的域名，不处理
        }
        
        if (!$domain instanceof Domain) {
            return;
        }
        
        try {
            // 规则已经是Cloudflare格式，直接使用（无需转换）
            $credentials = $this->getCredentials($domain);
            if (empty($credentials)) {
                error_log("Cloudflare规则推送失败 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}]: 未配置账户凭据");
                return;
            }
            
            $zoneId = $domain->getData(Domain::fields_ZONE_ID);
            if (empty($zoneId)) {
                error_log("Cloudflare规则推送失败 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}]: 未配置Zone ID");
                return;
            }
            
            // 调用适配器的putRules方法推送
            $result = $this->adapter->putRules($zoneId, $rules, $credentials);
            
            // 记录结果
            if ($result['success'] ?? false) {
                // 记录成功日志
                if (defined('CLI') && CLI) {
                    echo sprintf(
                        "Cloudflare规则推送成功 [域名: %s, 触发方式: %s, 规则数: %d]\n",
                        $domain->getData(Domain::fields_DOMAIN_NAME),
                        $triggerType,
                        count($rules)
                    );
                }
            } else {
                // 记录错误日志
                $errorMessage = $result['message'] ?? '未知错误';
                error_log("Cloudflare规则推送失败 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}, 触发方式: {$triggerType}]: {$errorMessage}");
            }
        } catch (\Exception $e) {
            // 记录异常
            error_log("Cloudflare规则推送异常 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}, 触发方式: {$triggerType}]: " . $e->getMessage());
        }
    }

    /**
     * 获取域名使用的凭据
     * 
     * @param Domain $domain 域名对象
     * @return array 凭据数组
     */
    private function getCredentials(Domain $domain): array
    {
        // 1. 如果域名有自定义凭据，优先使用
        $credentials = $domain->getCredentialsArray();
        if (!empty($credentials)) {
            return $credentials;
        }

        // 2. 如果指定了 account_id，使用该账户的凭据
        $accountId = $domain->getData(Domain::fields_ACCOUNT_ID);
        if ($accountId) {
            /** @var \Weline\Cdn\Model\Account $account */
            $account = ObjectManager::getInstance(\Weline\Cdn\Model\Account::class)->reset()->load($accountId);
            if ($account->getId()) {
                $accountCredentials = $account->getCredentialsArray();
                if (!empty($accountCredentials)) {
                    return $accountCredentials;
                }
            }
        }

        // 3. 如果继承默认账户，使用默认账户的凭据
        if ($domain->isInheritDefault()) {
            $adapter = $domain->getData(Domain::fields_ADAPTER);
            /** @var \Weline\Cdn\Service\AccountManager $accountManager */
            $accountManager = ObjectManager::getInstance(\Weline\Cdn\Service\AccountManager::class);
            $defaultAccount = $accountManager->getDefaultAccount($adapter);
            if ($defaultAccount) {
                $defaultCredentials = $defaultAccount->getCredentialsArray();
                if (!empty($defaultCredentials)) {
                    return $defaultCredentials;
                }
            }
        }

        return [];
    }
}
