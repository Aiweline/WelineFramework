<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Console\Command;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Service\FeedGeneratorService;

/**
 * 生成Feed命令
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class GenerateFeed implements CommandInterface
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): mixed
    {
        $feedId = $args[0] ?? null;
        $format = $args[1] ?? 'json_feed';

        if (!$feedId) {
            echo "用法: php bin/m geo:feed:generate <feed_id> [format]\n";
            echo "示例: php bin/m geo:feed:generate 1 json_feed\n";
            return false;
        }

        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);

            if (!$feed->getId()) {
                echo "错误: Feed ID {$feedId} 不存在\n";
                return false;
            }

            /** @var FeedGeneratorService $feedGenerator */
            $feedGenerator = ObjectManager::getInstance(FeedGeneratorService::class);
            $feedContent = $feedGenerator->generateFeed($feed, $format);

            // 更新最后生成时间
            $feed->setData(Feed::schema_fields_LAST_GENERATED_AT, time());
            $feed->save();

            echo "Feed生成成功！\n";
            echo "Feed名称: {$feed->getData(Feed::schema_fields_FEED_NAME)}\n";
            echo "格式: {$format}\n";
            echo "内容长度: " . strlen($feedContent) . " 字节\n";

            return true;
        } catch (\Exception $e) {
            echo "错误: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '生成Feed';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return '生成Feed命令。用法: php bin/m geo:feed:generate <feed_id> [format]';
    }
}

