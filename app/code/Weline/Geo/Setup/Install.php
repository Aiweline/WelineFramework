<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Geo\Model\Platform;
use Weline\Geo\Model\PlatformAccount;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\FeedItem;
use Weline\Geo\Model\PushLog;
use Weline\Geo\Model\WebsiteProtocolConfig;

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
        $websiteProtocolConfig = ObjectManager::getInstance(WebsiteProtocolConfig::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($websiteProtocolConfig);
        $websiteProtocolConfig->setup($modelSetup, $context);

    }
}
