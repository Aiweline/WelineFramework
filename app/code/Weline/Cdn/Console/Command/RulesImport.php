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
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 导入规则命令
 * 
 * 用法：php bin/w cdn:rules:import --domain=example.com
 * 
 * @package Weline_Cdn
 */
class RulesImport extends CommandAbstract implements CommandInterface
{
    private RuleManager $ruleManager;

    public function __construct()
    {
        $this->ruleManager = ObjectManager::getInstance(RuleManager::class);
    }

    /**
     * 命令描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '从CDN导入规则';
    }

    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cdn:rules:import',
            $this->tip(),
            [
                '--domain' => '域名ID或名称（必需）',
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
        $domain = $data['domain'] ?? '';

        if (empty($domain)) {
            $this->printer->error(__('域名不能为空，请使用 --domain=域名ID或名称'));
            return;
        }

        try {
            // 获取域名
            /** @var Domain $domainModel */
            $domainModel = ObjectManager::getInstance(Domain::class);
            
            if (is_numeric($domain)) {
                $domainObj = $domainModel->reset()->load((int)$domain);
            } else {
                $domainObj = $domainModel->reset()
                    ->where(Domain::schema_fields_DOMAIN_NAME, $domain)
                    ->find()
                    ->fetch();
            }

            if (!$domainObj->getData(Domain::schema_fields_DOMAIN_ID)) {
                $this->printer->error(__('域名不存在：%{1}', [$domain]));
                return;
            }

            $this->printer->note(__('正在导入规则...'));

            // 导入规则
            $result = $this->ruleManager->importRules($domainObj);

            if ($result['success']) {
                // 保存为域名覆盖规则
                $domainObj->setRulesOverrideArray($result['rules']);
                $domainObj->save();

                $rulesCount = count($result['rules']);
                $this->printer->success(__('规则导入成功，共导入 %{1} 条规则', [$rulesCount]));
                
                if ($rulesCount > 0) {
                    $this->printer->note(__('规则已保存为域名覆盖规则'));
                }
            } else {
                $this->printer->error(__('规则导入失败：%{1}', [$result['message'] ?? '未知错误']));
            }

        } catch (\Exception $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }
}

