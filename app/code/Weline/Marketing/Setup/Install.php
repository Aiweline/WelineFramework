<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Marketing\Model\Rule\Rule;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Campaign\Campaign;
use Weline\Marketing\Model\RuleUsage\RuleUsage;

class Install implements InstallInterface
{
    /**
     * 安装模块
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 安装规则表
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($rule);
        $rule->setup($modelSetup, $context);
        
        // 安装优惠券表
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($coupon);
        $coupon->setup($modelSetup, $context);
        
        // 安装活动表
        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($campaign);
        $campaign->setup($modelSetup, $context);
        
        // 安装规则使用记录表
        /** @var RuleUsage $ruleUsage */
        $ruleUsage = ObjectManager::getInstance(RuleUsage::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($ruleUsage);
        $ruleUsage->setup($modelSetup, $context);
    }
}

