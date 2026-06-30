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
#[Acl('Weline_Geo::push_list', '推送管理', 'mdi-send', '推送管理', 'Weline_Geo::geo_manager')]
class Push extends BackendController
{
    /**
     * 推送历史
     * 
     * @return string
     */
    #[Acl('Weline_Geo::push_list_index', '查看推送历史', 'mdi-history', '查看推送历史')]
    public function index(): string
    {
        try {
            /** @var PushLog $pushLogModel */
            $pushLogModel = ObjectManager::getInstance(PushLog::class);
            $logs = $pushLogModel->pagination()->order('created_at', 'DESC')->select()->fetch();
            
            $this->assign('logs', $logs->getItems());
            $this->assign('pagination', $logs->getPagination());
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载推送历史失败：%{1}', $e->getMessage()));
            $this->assign('logs', []);
            return $this->fetch();
        }
    }

    /**
     * 一键推送界面
     * 
     * @return string
     */
    #[Acl('Weline_Geo::push_push', '一键推送', 'mdi-send', '一键推送')]
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
    #[Acl('Weline_Geo::push_execute', '执行推送', 'mdi-play', '执行推送')]
    public function execute(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $feedId = (int)$this->request->getPost('feed_id', 0);
            $platformIds = $this->request->getPost('platform_ids', []);
            
            if ($feedId <= 0) {
                return $this->jsonResponse(false, __('请选择Feed'));
            }

            if (empty($platformIds) || !is_array($platformIds)) {
                return $this->jsonResponse(false, __('请选择推送平台'));
            }

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);
            
            if (!$feed->getId()) {
                return $this->jsonResponse(false, __('Feed不存在'));
            }

            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);
            $results = $pushService->pushFeedToPlatforms($feed, $platformIds, PushLog::TYPE_MANUAL);

            $successCount = 0;
            $failCount = 0;
            $messages = [];

            foreach ($results as $platformId => $result) {
                if ($result->success) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                $messages[] = [
                    'platform_id' => $platformId,
                    'success' => $result->success,
                    'message' => $result->message,
                ];
            }

            $message = "推送完成：成功 {$successCount} 个，失败 {$failCount} 个";

            return $this->jsonResponse($successCount > 0, $message, [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'results' => $messages,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('推送失败：%{1}', $e->getMessage()));
        }
    }
}
