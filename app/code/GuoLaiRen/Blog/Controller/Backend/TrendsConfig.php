<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * Trends 配置控制器
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Model\TrendsConfig as TrendsConfigModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\ObjectManager;

#[Acl('GuoLaiRen_Blog::trends_config', 'Trends 配置', 'mdi mdi-chart-timeline-variant', 'Google Trends 与自动发文配置', 'Weline_Backend::blog_group')]
class TrendsConfig extends BackendController
{
    #[Acl('GuoLaiRen_Blog::trends_config_index', '查看 Trends 配置', 'mdi-cog', '查看 Trends 配置')]
    public function index(): string
    {
        $configs = [
            'guolairen_blog_trends_api_type' => TrendsConfigModel::get(TrendsConfigModel::KEY_API_TYPE, TrendsConfigModel::API_TYPE_NONE),
            'guolairen_blog_trends_serpapi_key' => TrendsConfigModel::get(TrendsConfigModel::KEY_SERPAPI_KEY),
            'guolairen_blog_trends_service_account_json' => TrendsConfigModel::get(TrendsConfigModel::KEY_SERVICE_ACCOUNT_JSON),
            'guolairen_blog_trends_growth_comparison' => TrendsConfigModel::get(TrendsConfigModel::KEY_GROWTH_COMPARISON, TrendsConfigModel::GROWTH_BOTH),
            'guolairen_blog_trends_growth_threshold' => TrendsConfigModel::get(TrendsConfigModel::KEY_GROWTH_THRESHOLD, '0'),
            'guolairen_blog_trends_region' => TrendsConfigModel::get(TrendsConfigModel::KEY_REGION, 'US'),
            'guolairen_blog_trends_default_language' => TrendsConfigModel::get(TrendsConfigModel::KEY_DEFAULT_LANGUAGE, 'en_US'),
            'guolairen_blog_trends_publish_as_draft' => TrendsConfigModel::get(TrendsConfigModel::KEY_PUBLISH_AS_DRAFT, '1'),
        ];

        $this->assign('configs', $configs);
        $this->assign('page_title', __('Trends 配置'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('Trends 配置'));

        return $this->fetch();
    }

    #[Acl('GuoLaiRen_Blog::trends_config_save', '保存 Trends 配置', 'mdi-content-save', '保存 Trends 配置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode(['success' => false, 'message' => __('无效的请求方法')], JSON_UNESCAPED_UNICODE);
        }

        try {
            $configs = $this->request->getPost('config', []);
            $allowedKeys = [
                TrendsConfigModel::KEY_API_TYPE,
                TrendsConfigModel::KEY_SERPAPI_KEY,
                TrendsConfigModel::KEY_SERVICE_ACCOUNT_JSON,
                TrendsConfigModel::KEY_GROWTH_COMPARISON,
                TrendsConfigModel::KEY_GROWTH_THRESHOLD,
                TrendsConfigModel::KEY_REGION,
                TrendsConfigModel::KEY_DEFAULT_LANGUAGE,
                TrendsConfigModel::KEY_PUBLISH_AS_DRAFT,
            ];

            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $configs)) {
                    TrendsConfigModel::set($key, (string)$configs[$key]);
                }
            }

            /** @var Clear $cache */
            $cache = ObjectManager::getInstance(Clear::class);
            $cache->execute(['-f']);

            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode(['success' => true, 'message' => __('保存成功')], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->request->getResponse()->setHeader('Content-Type', 'application/json');
            return json_encode(['success' => false, 'message' => __('保存失败：%{1}', $e->getMessage())], JSON_UNESCAPED_UNICODE);
        }
    }
}
