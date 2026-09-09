<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\Platform;
use Weline\Geo\Model\PushLog;
use Weline\Geo\Service\PushService;

/**
 * 推送管理控制器
 * 
 * @package Weline_Geo
 */
#[Acl('Weline_Geo::push_list', '推送管理', 'arrow-right', '推送管理', 'Weline_Geo::geo_manager')]
class Push extends BackendController
{
    /**
     * 推送历史
     * 
     * @return string
     */
    #[Acl('Weline_Geo::push_list_index', '查看推送历史', 'history', '查看推送历史')]
    public function index(): string
    {
        try {
            /** @var PushLog $pushLogModel */
            $pushLogModel = ObjectManager::getInstance(PushLog::class);
            $logs = $pushLogModel->pagination()->order('created_at', 'DESC')->select()->fetch();
            $items = $logs->getItems();
            $scheduledFailures = $this->loadScheduledFailures();

            $feedIds = [];
            $platformIds = [];
            foreach ($items as $row) {
                $feedIds[] = (int)($row['feed_id'] ?? 0);
                $platformIds[] = (int)($row['platform_id'] ?? 0);
            }
            foreach ($scheduledFailures as $row) {
                $feedIds[] = (int)($row['feed_id'] ?? 0);
                $platformIds[] = (int)($row['platform_id'] ?? 0);
            }

            $this->assign('logs', $items);
            $this->assign('scheduled_failures', $scheduledFailures);
            $this->assign('pagination', $logs->getPagination());
            $this->assign('feed_names', $this->loadFeedNames($feedIds));
            $this->assign('platform_names', $this->loadPlatformNames($platformIds));
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载推送历史失败：%{1}', $e->getMessage()));
            $this->assign('logs', []);
            $this->assign('scheduled_failures', []);
            $this->assign('feed_names', []);
            $this->assign('platform_names', []);
            return $this->fetch();
        }
    }

    /**
     * 一键推送界面
     * 
     * @return string
     */
    #[Acl('Weline_Geo::push_push', '一键推送', 'arrow-right', '一键推送')]
    public function push(): string
    {
        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feeds = $feedModel->where(Feed::schema_fields_IS_ENABLED, 1)->select()->fetchArray();

            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(Platform::class);
            $platforms = $platformModel->where(Platform::schema_fields_IS_ENABLED, 1)->select()->fetchArray();

            $this->assign('feeds', $feeds);
            $this->assign('platforms', $platforms);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载推送页面失败：%{1}', $e->getMessage()));
            $this->assign('feeds', []);
            $this->assign('platforms', []);
            return $this->fetch();
        }
    }

    /**
     * 执行推送
     * 
     * @return string
     */
    #[Acl('Weline_Geo::push_execute', '执行推送', 'play', '执行推送')]
    public function execute(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        $retryLogId = (int)$this->request->getPost('log_id', 0);
        if ($retryLogId > 0) {
            return $this->retryScheduledFailure($retryLogId);
        }

        try {
            $feedId = (int)$this->request->getPost('feed_id', 0);
            $platformIds = $this->normalizePlatformIds($this->request->getPost('platform_ids', []));

            if ($feedId <= 0) {
                return $this->jsonResponse(false, __('请选择内容源'));
            }

            if ($platformIds === []) {
                return $this->jsonResponse(false, __('请选择推送平台'));
            }

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);

            if (!$feed->getId()) {
                return $this->jsonResponse(false, __('内容源不存在'));
            }

            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);
            // Manual admin push runs sync so PushLog appears immediately on this page.
            $results = $pushService->pushFeedToPlatformsSync($feed, $platformIds, PushLog::TYPE_MANUAL);
            $platformNames = $this->loadPlatformNames($platformIds);

            $successCount = 0;
            $failCount = 0;
            $messages = [];
            $failLines = [];

            foreach ($results as $platformId => $result) {
                $platformId = (int)$platformId;
                $platformName = $platformNames[$platformId] ?? ('#' . $platformId);
                if ($result->success) {
                    $successCount++;
                } else {
                    $failCount++;
                    $reason = trim((string)$result->message);
                    if ($reason === '') {
                        $reason = (string)__('未知错误');
                    }
                    $failLines[] = $platformName . ': ' . $reason;
                }
                $messages[] = [
                    'platform_id' => $platformId,
                    'platform_name' => $platformName,
                    'success' => $result->success,
                    'message' => (string)$result->message,
                ];
            }

            $summary = (string)__('推送完成：成功 %{1} 个，失败 %{2} 个', [$successCount, $failCount]);
            if ($failLines !== []) {
                $summary .= "\n" . implode("\n", $failLines);
            }

            return $this->jsonResponse($successCount > 0 && $failCount === 0, $summary, [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $messages,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('推送失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 重试失败推送（人工同步，写入新 PushLog）
     */
    private function retryScheduledFailure(int $logId): string
    {
        try {
            if ($logId <= 0) {
                return $this->jsonResponse(false, __('缺少推送记录'));
            }

            /** @var PushLog $logModel */
            $logModel = ObjectManager::getInstance(PushLog::class);
            $log = $logModel->load($logId);
            if (!$log->getId()) {
                return $this->jsonResponse(false, __('推送记录不存在'));
            }

            $pushType = (string)$log->getData(PushLog::schema_fields_PUSH_TYPE);
            $status = (string)$log->getData(PushLog::schema_fields_STATUS);
            $allowedTypes = [PushLog::TYPE_SCHEDULED, PushLog::TYPE_AUTO];
            if ($status !== PushLog::STATUS_FAILED || !in_array($pushType, $allowedTypes, true)) {
                return $this->jsonResponse(false, __('仅可重试失败的定时推送'));
            }

            $feedId = (int)$log->getData(PushLog::schema_fields_FEED_ID);
            $platformId = (int)$log->getData(PushLog::schema_fields_PLATFORM_ID);

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);
            if (!$feed->getId()) {
                return $this->jsonResponse(false, __('内容源不存在'));
            }

            /** @var Platform $platformModel */
            $platformModel = ObjectManager::getInstance(Platform::class);
            $platform = $platformModel->load($platformId);
            if (!$platform->getId()) {
                return $this->jsonResponse(false, __('平台不存在'));
            }

            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);
            $result = $pushService->pushFeedSync($feed, $platform, null, PushLog::TYPE_MANUAL);
            $platformName = (string)($platform->getData(Platform::schema_fields_PLATFORM_NAME) ?? ('#' . $platformId));
            $message = $result->success
                ? (string)__('重试成功：%{1}', $platformName)
                : (string)__('重试失败：%{1} — %{2}', [$platformName, (string)$result->message]);

            return $this->jsonResponse($result->success, $message, [
                'log_id' => $logId,
                'platform_id' => $platformId,
                'success' => $result->success,
                'detail' => (string)$result->message,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('重试失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 最近定时推送失败（含历史 auto 类型），供管理页优先处理。
     *
     * @return list<array<string, mixed>>
     */
    private function loadScheduledFailures(): array
    {
        /** @var PushLog $logModel */
        $logModel = ObjectManager::getInstance(PushLog::class);
        $rows = $logModel->reset()
            ->where(PushLog::schema_fields_STATUS, PushLog::STATUS_FAILED)
            ->where(PushLog::schema_fields_PUSH_TYPE, [PushLog::TYPE_SCHEDULED, PushLog::TYPE_AUTO], 'IN')
            ->order(PushLog::schema_fields_CREATED_AT, 'DESC')
            ->limit(30)
            ->select()
            ->fetchArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadFeedNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        /** @var Feed $feedModel */
        $feedModel = ObjectManager::getInstance(Feed::class);
        $rows = $feedModel->reset()->select()->fetchArray();
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && in_array($id, $ids, true)) {
                $map[$id] = (string)($row['feed_name'] ?? ('#' . $id));
            }
        }

        return $map;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadPlatformNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        /** @var Platform $platformModel */
        $platformModel = ObjectManager::getInstance(Platform::class);
        $rows = $platformModel->reset()->select()->fetchArray();
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && in_array($id, $ids, true)) {
                $map[$id] = (string)($row['platform_name'] ?? ('#' . $id));
            }
        }

        return $map;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizePlatformIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = array_filter(array_map('trim', explode(',', $raw)));
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        return \json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
