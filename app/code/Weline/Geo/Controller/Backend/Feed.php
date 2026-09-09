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
use Weline\Geo\Model\Feed as FeedModel;
use Weline\Geo\Service\FeedGeneratorService;

/**
 * Feed管理控制器
 * 
 * @package Weline_Geo
 */
#[Acl('Weline_Geo::feed_list', 'Feed管理', 'circle', 'Feed管理', 'Weline_Geo::geo_manager')]
class Feed extends BackendController
{
    /**
     * Feed列表
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_list_index', '查看Feed列表', 'circle', '查看Feed列表')]
    public function index(): string
    {
        try {
            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            $feeds = $feedModel->pagination()->select()->fetch();
            
            $this->assign('feeds', $feeds->getItems());
            $this->assign('pagination', $feeds->getPagination());
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载Feed列表失败：%{1}', $e->getMessage()));
            $this->assign('feeds', []);
            return $this->fetch();
        }
    }

    /**
     * 编辑Feed
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_edit', '编辑Feed', 'edit', '编辑Feed')]
    public function edit(): string
    {
        try {
            $id = (int)$this->request->getParam('id', 0);
            if ($id <= 0) {
                Message::warning(__('内容源由系统维护，不可手工新建；请从列表进入启用设置。'));
                $this->redirect('geo/backend/feed');
                return '';
            }

            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            $feed = $feedModel->load($id);
            if (!$feed->getId()) {
                Message::error(__('Feed不存在'));
                $this->redirect('geo/backend/feed');
                return '';
            }

            $this->assign('feed', $feed);
            $this->assign('feed_types', [
                FeedModel::TYPE_CONTENT => __('内容'),
                FeedModel::TYPE_PRODUCT => __('产品'),
                FeedModel::TYPE_ARTICLE => __('文章'),
                FeedModel::TYPE_CUSTOM => __('自定义'),
            ]);
            $this->assign('source_types', [
                FeedModel::SOURCE_DATABASE => __('数据库'),
                FeedModel::SOURCE_API => __('API'),
                FeedModel::SOURCE_CUSTOM => __('自定义'),
            ]);
            $this->assign('update_frequencies', [
                FeedModel::FREQUENCY_EVERY_10_MIN => __('每10分钟（有变更才生成）'),
                FeedModel::FREQUENCY_HOURLY => __('每小时'),
                FeedModel::FREQUENCY_DAILY => __('每天'),
                FeedModel::FREQUENCY_WEEKLY => __('每周'),
                FeedModel::FREQUENCY_REALTIME => __('实时（已停用，等同定时）'),
            ]);
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载Feed失败：%{1}', $e->getMessage()));
            $this->redirect('geo/backend/feed');
            return '';
        }
    }

    /**
     * 保存Feed
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_save', '保存Feed', 'save', '保存Feed')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $id = (int)$this->request->getPost('id', 0);
            if ($id <= 0) {
                return $this->jsonResponse(false, __('内容源由系统维护，不可手工新建'));
            }

            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            $feed = $feedModel->load($id);
            if (!$feed->getId()) {
                return $this->jsonResponse(false, __('Feed不存在'));
            }

            // Checkbox omitted when unchecked → treat as disabled.
            $isEnabled = $this->request->getPost('is_enabled') !== null ? 1 : 0;
            $feed->setData(FeedModel::schema_fields_IS_ENABLED, $isEnabled);
            $feed->setData(FeedModel::schema_fields_UPDATED_AT, time());
            $feed->save();

            return $this->jsonResponse(true, __('Saved successfully'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 生成Feed
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_generate', '生成Feed', 'refresh', '生成Feed')]
    public function generate(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $feedId = (int)$this->request->getPost('feed_id', 0);
            $format = $this->request->getPost('format', 'json_feed');
            
            if ($feedId <= 0) {
                return $this->jsonResponse(false, __('请选择Feed'));
            }

            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            $feed = $feedModel->load($feedId);
            
            if (!$feed->getId()) {
                return $this->jsonResponse(false, __('Feed不存在'));
            }

            /** @var FeedGeneratorService $feedGenerator */
            $feedGenerator = ObjectManager::getInstance(FeedGeneratorService::class);
            $feedContent = $feedGenerator->generateFeed($feed, $format);

            // 更新最后生成时间
            $feed->setData(FeedModel::schema_fields_LAST_GENERATED_AT, time());
            $feed->save();

            return $this->jsonResponse(true, __('生成成功'), ['feed_content' => $feedContent]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('生成失败：%{1}', $e->getMessage()));
        }
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
