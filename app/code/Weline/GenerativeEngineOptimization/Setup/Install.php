<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\GenerativeEngineOptimization\Model\Platform;
use Weline\GenerativeEngineOptimization\Model\PlatformAccount;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\FeedItem;
use Weline\GenerativeEngineOptimization\Model\PushLog;
use Weline\Queue\Model\Queue\Type;

class Install implements InstallInterface
{
    /**
     * 安装模块
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 安装Platform表
        /** @var Platform $platform */
        $platform = ObjectManager::getInstance(Platform::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($platform);
        $platform->setup($modelSetup, $context);
        
        // 安装PlatformAccount表
        /** @var PlatformAccount $platformAccount */
        $platformAccount = ObjectManager::getInstance(PlatformAccount::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($platformAccount);
        $platformAccount->setup($modelSetup, $context);
        
        // 安装Feed表
        /** @var Feed $feed */
        $feed = ObjectManager::getInstance(Feed::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($feed);
        $feed->setup($modelSetup, $context);
        
        // 安装FeedItem表
        /** @var FeedItem $feedItem */
        $feedItem = ObjectManager::getInstance(FeedItem::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($feedItem);
        $feedItem->setup($modelSetup, $context);
        
        // 安装PushLog表
        /** @var PushLog $pushLog */
        $pushLog = ObjectManager::getInstance(PushLog::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($pushLog);
        $pushLog->setup($modelSetup, $context);

        // 注册队列类型
        $this->registerQueueTypes();
    }

    /**
     * 注册队列类型
     * 
     * @return void
     */
    protected function registerQueueTypes(): void
    {
        /** @var Type $queueType */
        $queueType = ObjectManager::getInstance(Type::class);

        // 注册Feed生成队列类型
        $generateType = $queueType->where(Type::fields_class, 'Weline\GenerativeEngineOptimization\Queue\FeedGenerateQueue')
            ->find()
            ->fetch();

        if (!$generateType->getId()) {
            $generateType = ObjectManager::getInstance(Type::class);
            $generateType->setData([
                Type::fields_name => 'GEO Feed生成队列',
                Type::fields_tip => '生成Feed文件并保存到pub目录',
                Type::fields_module_name => 'Weline_GenerativeEngineOptimization',
                Type::fields_class => 'Weline\GenerativeEngineOptimization\Queue\FeedGenerateQueue',
                Type::fields_attributes => '',
                Type::fields_enable => 1,
            ]);
            $generateType->save();
        }

        // 注册Feed推送队列类型
        $pushType = $queueType->where(Type::fields_class, 'Weline\GenerativeEngineOptimization\Queue\FeedPushQueue')
            ->find()
            ->fetch();

        if (!$pushType->getId()) {
            $pushType = ObjectManager::getInstance(Type::class);
            $pushType->setData([
                Type::fields_name => 'GEO Feed推送队列',
                Type::fields_tip => '推送Feed到AI搜索引擎平台',
                Type::fields_module_name => 'Weline_GenerativeEngineOptimization',
                Type::fields_class => 'Weline\GenerativeEngineOptimization\Queue\FeedPushQueue',
                Type::fields_attributes => '',
                Type::fields_enable => 1,
            ]);
            $pushType->save();
        }
    }
}
