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
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\Account;
use Weline\Cdn\Model\ApiRule;
use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * 规则管理服务
 * 
 * @package Weline_Cdn
 */
class RuleManager
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
     * 获取全局默认规则
     * 
     * @return array
     */
    public function getDefaultRules(): array
    {
        $rulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
        
        if (!file_exists($rulesFile)) {
            return [];
        }

        $content = file_get_contents($rulesFile);
        $rules = json_decode($content, true);
        
        return is_array($rules) ? $rules : [];
    }

    /**
     * 保存全局默认规则
     * 
     * @param array $rules 规则数组
     * @return bool
     */
    public function saveDefaultRules(array $rules): bool
    {
        $rulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
        
        $content = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        return file_put_contents($rulesFile, $content) !== false;
    }

    /**
     * 获取域名的合并规则（通用规则，适用于所有适配器）
     * 
     * @param Domain $domain 域名对象
     * @param string|null $triggerType 触发类型：'cron'（定时）或'realtime'（实时），null表示全部
     * @return array Cloudflare格式的规则数组（作为统一标准）
     */
    public function getMergedRules(Domain $domain, ?string $triggerType = null): array
    {
        // 1. 获取默认规则（Cloudflare格式，通用）
        $defaultRules = $this->getDefaultRules();
        
        // 2. 获取域名覆盖规则（Cloudflare格式，通用）
        $overrideRules = $domain->getRulesOverrideArray();
        
        // 3. 获取API注释规则（Cloudflare格式，通用，不指定适配器）
        $apiRules = $this->getApiRules($triggerType);
        
        // 4. 合并规则（优先级：API规则 > 域名规则 > 默认规则）
        // 这些规则是通用的，所有适配器都可以使用
        $mergedRules = array_merge(
            $defaultRules,
            $overrideRules,
            $apiRules
        );
        
        return $mergedRules;
    }

    /**
     * 获取API注释规则
     * 
     * @param string|null $triggerType 触发类型过滤：'cron'、'realtime' 或 null（全部）
     * @return array
     */
    private function getApiRules(?string $triggerType = null): array
    {
        /** @var ApiRule $apiRuleModel */
        $apiRuleModel = $this->objectManager->getInstance(ApiRule::class);
        
        $query = $apiRuleModel->clear()
            ->where(ApiRule::schema_fields_ENABLED, 1);
        
        // 如果指定了触发类型，只获取对应类型的规则
        if ($triggerType !== null) {
            $query->where(ApiRule::schema_fields_TRIGGER, $triggerType);
        }
        
        $apiRules = $query->select()->fetch();
        
        $rules = [];
        foreach ($apiRules as $apiRule) {
            $rules[] = $apiRule->toCloudflareRule();
        }
        
        return $rules;
    }

    /**
     * 从CDN导入规则
     * 
     * @param Domain $domain 域名模型
     * @return array ['success' => bool, 'message' => string, 'rules' => array]
     * @throws Core
     */
    public function importRules(Domain $domain): array
    {
        $adapter = $this->adapterResolver->getAdapter($domain->getData(Domain::schema_fields_ADAPTER));
        if (!$adapter) {
            throw new Core(__('适配器不存在：%{1}', [$domain->getData(Domain::schema_fields_ADAPTER)]));
        }

        // 获取凭据
        $credentials = $this->getCredentials($domain);
        if (empty($credentials)) {
            throw new Core(__('未配置账户凭据'));
        }

        $zoneId = $domain->getData(Domain::schema_fields_ZONE_ID);
        $rules = $adapter->getRules($zoneId, $credentials);

        return [
            'success' => true,
            'message' => __('规则导入成功'),
            'rules' => $rules
        ];
    }

    /**
     * 推送规则到CDN
     * 
     * @param Domain $domain 域名模型
     * @return array ['success' => bool, 'message' => string]
     * @throws Core
     */
    public function pushRules(Domain $domain): array
    {
        $adapter = $this->adapterResolver->getAdapter($domain->getData(Domain::schema_fields_ADAPTER));
        if (!$adapter) {
            throw new Core(__('适配器不存在：%{1}', [$domain->getData(Domain::schema_fields_ADAPTER)]));
        }

        // 获取合并后的规则
        $rules = $this->getMergedRules($domain);

        // 获取凭据
        $credentials = $this->getCredentials($domain);
        if (empty($credentials)) {
            throw new Core(__('未配置账户凭据'));
        }

        $zoneId = $domain->getData(Domain::schema_fields_ZONE_ID);
        $result = $adapter->putRules($zoneId, $rules, $credentials);

        return $result;
    }

    /**
     * 获取域名使用的凭据
     * 
     * @param Domain $domain 域名模型
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
        $accountId = $domain->getData(Domain::schema_fields_ACCOUNT_ID);
        if ($accountId) {
            $account = $this->objectManager->getInstance(Account::class)->reset()->load($accountId);
            if ($account->getId()) {
                $accountCredentials = $account->getCredentialsArray();
                if (!empty($accountCredentials)) {
                    return $accountCredentials;
                }
            }
        }

        // 3. 如果继承默认账户，使用默认账户的凭据
        if ($domain->isInheritDefault()) {
            $adapter = $domain->getData(Domain::schema_fields_ADAPTER);
            $defaultAccount = $this->accountManager->getDefaultAccount($adapter);
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

