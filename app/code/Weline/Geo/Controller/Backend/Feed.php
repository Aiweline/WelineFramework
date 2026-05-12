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
#[Acl('Weline_Geo::feed_list', 'Feed管理', 'mdi-rss', 'Feed管理', 'Weline_Geo::geo_manager')]
class Feed extends BackendController
{
    /**
     * Feed列表
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_list_index', '查看Feed列表', 'mdi-rss', '查看Feed列表')]
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
    #[Acl('Weline_Geo::feed_edit', '编辑Feed', 'mdi-pencil', '编辑Feed')]
    public function edit(): string
    {
        try {
            $id = (int)$this->request->getParam('id', 0);
            
            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            
            if ($id > 0) {
                $feed = $feedModel->load($id);
                if (!$feed->getId()) {
                    Message::error(__('Feed不存在'));
                    $this->redirect('geo/backend/feed');
                    return '';
                }
            } else {
                $feed = $feedModel;
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
                FeedModel::FREQUENCY_REALTIME => __('实时'),
                FeedModel::FREQUENCY_HOURLY => __('每小时'),
                FeedModel::FREQUENCY_DAILY => __('每天'),
                FeedModel::FREQUENCY_WEEKLY => __('每周'),
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
    #[Acl('Weline_Geo::feed_save', '保存Feed', 'mdi-content-save', '保存Feed')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $id = (int)$this->request->getPost('id', 0);
            $feedName = $this->request->getPost('feed_name', '');
            $feedType = $this->request->getPost('feed_type', FeedModel::TYPE_CONTENT);
            $sourceType = $this->request->getPost('source_type', FeedModel::SOURCE_DATABASE);
            $sourceConfig = $this->request->getPost('source_config', '{}');
            $feedUrl = $this->request->getPost('feed_url', '');
            $updateFrequency = $this->request->getPost('update_frequency', FeedModel::FREQUENCY_DAILY);
            // 默认启用自动推送（新建时默认为1，编辑时如果未提交则保持原值）
            $isAutoPushPost = $this->request->getPost('is_auto_push');
            if ($isAutoPushPost === null) {
                // 表单未提交该字段，新建时默认为1，编辑时保持原值
                if ($id > 0) {
                    $existingFeed = $feedModel->load($id);
                    $isAutoPush = (int)($existingFeed->getData(FeedModel::schema_fields_IS_AUTO_PUSH) ?? 1);
                } else {
                    $isAutoPush = 1; // 新建时默认启用
                }
            } else {
                $isAutoPush = (int)$isAutoPushPost;
            }
            $isEnabled = (int)$this->request->getPost('is_enabled', 1);
            $config = $this->request->getPost('config', '{}');

            if (empty($feedName)) {
                return $this->jsonResponse(false, __('请填写Feed名称'));
            }

            /** @var FeedModel $feedModel */
            $feedModel = ObjectManager::getInstance(FeedModel::class);
            
            if ($id > 0) {
                $feed = $feedModel->load($id);
                if (!$feed->getId()) {
                    return $this->jsonResponse(false, __('Feed不存在'));
                }
            } else {
                $feed = $feedModel;
            }

            $feed->setData([
                FeedModel::schema_fields_FEED_NAME => $feedName,
                FeedModel::schema_fields_FEED_TYPE => $feedType,
                FeedModel::schema_fields_SOURCE_TYPE => $sourceType,
                FeedModel::schema_fields_SOURCE_CONFIG => $sourceConfig,
                FeedModel::schema_fields_FEED_URL => $feedUrl,
                FeedModel::schema_fields_UPDATE_FREQUENCY => $updateFrequency,
                FeedModel::schema_fields_IS_AUTO_PUSH => $isAutoPush,
                FeedModel::schema_fields_IS_ENABLED => $isEnabled,
                FeedModel::schema_fields_CONFIG => $config,
            ]);

            $feed->save();

            return $this->jsonResponse(true, __('保存成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 生成Feed
     * 
     * @return string
     */
    #[Acl('Weline_Geo::feed_generate', '生成Feed', 'mdi-refresh', '生成Feed')]
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
}
