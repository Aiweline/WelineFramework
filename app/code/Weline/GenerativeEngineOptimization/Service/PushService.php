<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Service;

use Weline\GenerativeEngineOptimization\Adapter\BaseAdapter;
use Weline\GenerativeEngineOptimization\Adapter\PushResult;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\Platform;
use Weline\GenerativeEngineOptimization\Model\PlatformAccount;
use Weline\GenerativeEngineOptimization\Model\PushLog;
use Weline\Framework\Manager\ObjectManager;

/**
 * 推送服务
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class PushService
{
    /**
     * 推送Feed到平台（入队方式）
     * 
     * @param Feed $feed Feed配置
     * @param Platform $platform 平台配置
     * @param PlatformAccount|null $account 平台账户（如果为null，使用默认账户）
     * @param string $pushType 推送类型（manual, auto, scheduled）
     * @return PushResult 推送结果（包含队列任务ID）
     */
    public function pushFeed(
        Feed $feed,
        Platform $platform,
        ?PlatformAccount $account = null,
        string $pushType = PushLog::TYPE_MANUAL
    ): PushResult {
        // 入队推送任务
        /** @var FeedQueueService $queueService */
        $queueService = ObjectManager::getInstance(FeedQueueService::class);
        $queueId = $queueService->enqueueFeedPush($feed->getId(), [$platform->getId()], $pushType);
        
        return new PushResult(true, "推送任务已入队，队列ID: {$queueId}", ['queue_id' => $queueId]);
    }

    /**
     * 同步推送Feed到平台（供队列处理器调用）
     * 
     * @param Feed $feed Feed配置
     * @param Platform $platform 平台配置
     * @param PlatformAccount|null $account 平台账户（如果为null，使用默认账户）
     * @param string $pushType 推送类型（manual, auto, scheduled）
     * @return PushResult 推送结果
     */
    public function pushFeedSync(
        Feed $feed,
        Platform $platform,
        ?PlatformAccount $account = null,
        string $pushType = PushLog::TYPE_MANUAL
    ): PushResult {
        try {
            // 如果是定时推送，检查是否启用自动推送
            if ($pushType === PushLog::TYPE_SCHEDULED && !$feed->isAutoPush()) {
                return new PushResult(false, 'Feed已关闭自动推送');
            }

            // 获取适配器
            /** @var PlatformAdapterService $adapterService */
            $adapterService = ObjectManager::getInstance(PlatformAdapterService::class);
            $adapter = $adapterService->getAdapter($platform);
            
            if (!$adapter) {
                return new PushResult(false, "平台 {$platform->getData(Platform::fields_PLATFORM_CODE)} 不支持");
            }

            // 获取账户
            if (!$account) {
                $account = $this->getDefaultAccount($platform);
            }

            if (!$account || !$account->isAvailable()) {
                return new PushResult(false, '没有可用的平台账户');
            }

            // 生成Feed并写入pub目录，得到相对URL
            /** @var FeedGeneratorService $feedGenerator */
            $feedGenerator = ObjectManager::getInstance(FeedGeneratorService::class);
            $feedFormat = $platform->getData(Platform::fields_FEED_FORMAT) ?? 'json_feed';
            $relativeUrl = $feedGenerator->generateAndSaveFeed($feed, $feedFormat);

            // 使用Feed URL进行推送
            $feedUrl = $feed->getData(Feed::fields_FEED_URL) ?: $relativeUrl;

            $result = $adapter->pushFeedUrl($feedUrl, $account);

            // 记录日志
            $this->logPush($feed, $platform, $account, $pushType, $result);

            // 更新Feed最后推送时间
            if ($result->success) {
                $feed->setData(Feed::fields_LAST_PUSHED_AT, time());
                $feed->save();
            }

            return $result;
        } catch (\Exception $e) {
            // 记录错误日志
            $this->logPushError($feed, $platform, $account ?? null, $pushType, $e->getMessage());
            return new PushResult(false, $e->getMessage());
        }
    }

    /**
     * 批量推送Feed到多个平台（入队方式）
     * 
     * @param Feed $feed Feed配置
     * @param array $platformIds 平台ID数组（空数组表示所有平台）
     * @param string $pushType 推送类型
     * @return array 推送结果数组（包含队列任务ID）
     */
    public function pushFeedToPlatforms(Feed $feed, array $platformIds, string $pushType = PushLog::TYPE_MANUAL): array
    {
        // 入队推送任务
        /** @var FeedQueueService $queueService */
        $queueService = ObjectManager::getInstance(FeedQueueService::class);
        $queueId = $queueService->enqueueFeedPush($feed->getId(), $platformIds, $pushType);
        
        return [
            'queue_id' => $queueId,
            'message' => "批量推送任务已入队，队列ID: {$queueId}",
        ];
    }

    /**
     * 同步批量推送Feed到多个平台（供队列处理器调用）
     * 
     * @param Feed $feed Feed配置
     * @param array $platformIds 平台ID数组
     * @param string $pushType 推送类型
     * @return array 推送结果数组
     */
    public function pushFeedToPlatformsSync(Feed $feed, array $platformIds, string $pushType = PushLog::TYPE_MANUAL): array
    {
        $results = [];
        
        /** @var Platform $platformModel */
        $platformModel = ObjectManager::getInstance(Platform::class);
        
        foreach ($platformIds as $platformId) {
            $platform = $platformModel->load($platformId);
            if (!$platform->getId()) {
                $results[$platformId] = new PushResult(false, '平台不存在');
                continue;
            }

            $results[$platformId] = $this->pushFeedSync($feed, $platform, null, $pushType);
        }

        return $results;
    }

    /**
     * 获取平台的默认账户
     * 
     * @param Platform $platform 平台配置
     * @return PlatformAccount|null 默认账户
     */
    protected function getDefaultAccount(Platform $platform): ?PlatformAccount
    {
        /** @var PlatformAccount $accountModel */
        $accountModel = ObjectManager::getInstance(PlatformAccount::class);
        
        $account = $accountModel
            ->where('platform_id', $platform->getId())
            ->where('is_default', 1)
            ->where('is_active', 1)
            ->where('status', PlatformAccount::STATUS_ACTIVE)
            ->find()
            ->fetch();

        return $account->getId() ? $account : null;
    }

    /**
     * 记录推送日志
     * 
     * @param Feed $feed Feed配置
     * @param Platform $platform 平台配置
     * @param PlatformAccount $account 平台账户
     * @param string $pushType 推送类型
     * @param PushResult $result 推送结果
     * @return void
     */
    protected function logPush(
        Feed $feed,
        Platform $platform,
        PlatformAccount $account,
        string $pushType,
        PushResult $result
    ): void {
        /** @var PushLog $pushLog */
        $pushLog = ObjectManager::getInstance(PushLog::class);
        
        $pushLog->setData([
            PushLog::fields_FEED_ID => $feed->getId(),
            PushLog::fields_PLATFORM_ID => $platform->getId(),
            PushLog::fields_PLATFORM_ACCOUNT_ID => $account->getId(),
            PushLog::fields_PUSH_TYPE => $pushType,
            PushLog::fields_STATUS => $result->success ? PushLog::STATUS_SUCCESS : PushLog::STATUS_FAILED,
            PushLog::fields_ITEMS_COUNT => $result->itemsCount,
            PushLog::fields_RESPONSE_DATA => json_encode($result->responseData),
            PushLog::fields_ERROR_MESSAGE => $result->success ? '' : $result->message,
            PushLog::fields_PUSHED_AT => time(),
            PushLog::fields_CREATED_AT => time(),
        ]);
        
        $pushLog->save();
    }

    /**
     * 记录推送错误日志
     * 
     * @param Feed $feed Feed配置
     * @param Platform $platform 平台配置
     * @param PlatformAccount|null $account 平台账户
     * @param string $pushType 推送类型
     * @param string $errorMessage 错误消息
     * @return void
     */
    protected function logPushError(
        Feed $feed,
        Platform $platform,
        ?PlatformAccount $account,
        string $pushType,
        string $errorMessage
    ): void {
        /** @var PushLog $pushLog */
        $pushLog = ObjectManager::getInstance(PushLog::class);
        
        $pushLog->setData([
            PushLog::fields_FEED_ID => $feed->getId(),
            PushLog::fields_PLATFORM_ID => $platform->getId(),
            PushLog::fields_PLATFORM_ACCOUNT_ID => $account ? $account->getId() : null,
            PushLog::fields_PUSH_TYPE => $pushType,
            PushLog::fields_STATUS => PushLog::STATUS_FAILED,
            PushLog::fields_ITEMS_COUNT => 0,
            PushLog::fields_RESPONSE_DATA => '{}',
            PushLog::fields_ERROR_MESSAGE => $errorMessage,
            PushLog::fields_PUSHED_AT => time(),
            PushLog::fields_CREATED_AT => time(),
        ]);
        
        $pushLog->save();
    }
}
