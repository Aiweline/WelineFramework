<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * 添加CDN域名命令
 * 
 * 用法：php bin/w cdn:domain:add --site=main [--adapter=cloudflare] [--zone-id=xxx]
 * 
 * @package Weline_Cdn
 */
class DomainAdd extends CommandAbstract implements CommandInterface
{
    private AdapterResolver $adapterResolver;
    private AccountManager $accountManager;
    private RuleManager $ruleManager;

    public function __construct()
    {
        $this->adapterResolver = ObjectManager::getInstance(AdapterResolver::class);
        $this->accountManager = ObjectManager::getInstance(AccountManager::class);
        $this->ruleManager = ObjectManager::getInstance(RuleManager::class);
    }

    /**
     * 命令描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '添加CDN域名（从网站创建）';
    }

    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cdn:domain:add',
            $this->tip(),
            [
                '--site' => '网站代码（必需）',
                '--adapter' => '适配器代码（可选，默认cloudflare）',
                '--zone-id' => 'Zone ID（可选，会自动查询）',
            ],
            [],
            []
        );
    }

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return mixed|void
     */
    public function execute(array $args = [], array $data = [])
    {
        $siteCode = $data['site'] ?? '';
        $adapterCode = $data['adapter'] ?? 'cloudflare';
        $zoneId = $data['zone-id'] ?? '';

        if (empty($siteCode)) {
            $this->printer->error(__('网站代码不能为空，请使用 --site=网站代码'));
            return;
        }

        try {
            // 获取网站
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $website = $websiteModel->reset()
                ->where(Website::fields_CODE, $siteCode)
                ->find()
                ->fetch();

            if (!$website->getData(Website::fields_ID)) {
                $this->printer->error(__('网站不存在：%{1}', [$siteCode]));
                return;
            }

            // 检查是否已配置CDN域名
            /** @var Domain $domainModel */
            $domainModel = ObjectManager::getInstance(Domain::class);
            $existingDomain = $domainModel->reset()
                ->where(Domain::fields_SITE_ID, $website->getData(Website::fields_ID))
                ->find()
                ->fetch();

            if ($existingDomain->getData(Domain::fields_DOMAIN_ID)) {
                $this->printer->error(__('该网站已配置CDN域名'));
                return;
            }

            // 获取适配器
            $adapter = $this->adapterResolver->getAdapter($adapterCode);
            if (!$adapter) {
                $this->printer->error(__('适配器不存在：%{1}', [$adapterCode]));
                return;
            }

            // 获取域名（从网站URL解析）
            $websiteUrl = $website->getData(Website::fields_URL);
            $parsedUrl = parse_url($websiteUrl);
            $domainName = $parsedUrl['host'] ?? '';

            if (empty($domainName)) {
                $this->printer->error(__('无法从网站URL解析域名'));
                return;
            }

            // 如果没有提供Zone ID，尝试查询
            if (empty($zoneId)) {
                try {
                    $defaultAccount = $this->accountManager->getDefaultAccount($adapterCode);
                    if (!$defaultAccount) {
                        $this->printer->error(__('未找到默认账户，请先配置账户'));
                        return;
                    }

                    $credentials = $defaultAccount->getCredentialsArray();
                    $zoneInfo = $adapter->ensureZone($domainName, $credentials);
                    $zoneId = $zoneInfo['zone_id'];
                    
                    $this->printer->note(__('自动查询到Zone ID：%{1}', [$zoneId]));
                } catch (\Exception $e) {
                    $this->printer->error(__('查询Zone ID失败：%{1}', [$e->getMessage()]));
                    $this->printer->note(__('请使用 --zone-id=xxx 手动指定Zone ID'));
                    return;
                }
            }

            // 创建域名
            $domain = $domainModel->reset();
            $domain->setData(Domain::fields_SITE_ID, $website->getData(Website::fields_ID));
            $domain->setData(Domain::fields_ADAPTER, $adapterCode);
            $domain->setData(Domain::fields_DOMAIN_NAME, $domainName);
            $domain->setData(Domain::fields_ZONE_ID, $zoneId);
            $domain->setData(Domain::fields_INHERIT_DEFAULT, 1);
            $domain->setData(Domain::fields_ENABLED, 1);
            $domain->setData(Domain::fields_WARMUP_INTERVAL_SECONDS, 300);
            $domain->save();

            $this->printer->success(__('CDN域名创建成功：%{1}', [$domainName]));

            // 推送默认规则
            try {
                $this->ruleManager->pushRules($domain);
                $this->printer->success(__('默认规则推送成功'));
            } catch (\Exception $e) {
                $this->printer->warning(__('默认规则推送失败：%{1}', [$e->getMessage()]));
            }

        } catch (\Exception $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }
}

