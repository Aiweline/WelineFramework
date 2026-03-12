<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/18 12:44:40
 */

namespace Weline\UrlManager\Controller\Backend;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlManager;
use Weline\UrlManager\Model\UrlRewrite;

class Rewriter extends \Weline\Framework\App\Controller\BackendController
{
    public function get()
    {
        /**@var UrlRewrite $urlRewriteModel */
        $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);
        
        // 支持按 website_id 过滤
        $websiteIdFilter = $this->request->getGet('website_id');
        if ($websiteIdFilter !== null && $websiteIdFilter !== '') {
            $urlRewriteModel->where('main_table.' . UrlRewrite::schema_fields_WEBSITE_ID, (int)$websiteIdFilter);
        }
        
        // url_rewrite.url_id 为 varchar，url_manager.url_id 为 int；PostgreSQL 需显式类型一致，用 CAST 兼容 MySQL/Pg
        $rewrites = $urlRewriteModel->fields('main_table.*,main_table.path as rewrite_path,um.url_id,um.path,um.is_deleted')
            ->joinModel(UrlManager::class, 'um', 'main_table.url_id = CAST(um.url_id AS VARCHAR(255))', 'left')
            ->pagination()
            ->select()
            ->fetch();
        $this->assign('rewrites', $rewrites->getItems());
        $this->assign('pagination', $rewrites->getPagination());
        $this->assign('current_website_id', $websiteIdFilter ?? '');
        return $this->fetch();
    }

    public function post()
    {
        $data = $this->request->getPost();
        if (!isset($data['path'])) {
            $origin_path_arr = explode('::', $data['origin_path']);
            $data['path'] = strtolower(array_shift($origin_path_arr));
        } else {
            $data['url_identify'] = md5($data['path']);
        }
        
        // 确保 website_id 已设置，默认为当前网站或 0
        if (!isset($data['website_id']) || $data['website_id'] === '') {
            $data['website_id'] = UrlRewrite::getCurrentWebsiteId();
        } else {
            $data['website_id'] = (int)$data['website_id'];
        }
        
        /**@var UrlRewrite $urlRewriter */
        $urlRewriter = ObjectManager::getInstance(UrlRewrite::class);
        $urlRewriter->setData($data);
        try {
            $urlRewriter->save();
        } catch (\ReflectionException|Exception|ModelException $e) {
            MessageManager::error(__('重写失败！') . (DEV ? $e->getMessage() : ''));
        }
        MessageManager::success(__('重写成功！'));
        $this->redirect($this->_url->getBackendUrl('url-manager/backend/rewriter'));
    }

    public function form()
    {
        $uri_identify = $this->request->getGet('identify', '');
        // 支持通过 website_id 定位（避免不同站点同 url_identify 时取错）
        $websiteId = $this->request->getGet('website_id');
        
        /**@var UrlManager $urlManager */
        $urlManager = ObjectManager::getInstance(UrlManager::class);
        $query = $urlManager
            ->fields('main_table.*,ur.rewrite as rewrite_path,ur.' . UrlRewrite::schema_fields_WEBSITE_ID . ' as website_id')
            ->where('ur.' . UrlRewrite::schema_fields_URL_IDENTIFY, $uri_identify)
            ->joinModel(
                UrlRewrite::class,
                'ur',
                'main_table.identify=ur.url_identify',
                'right'
            );
        
        // 如果指定了 website_id，加入过滤条件
        if ($websiteId !== null && $websiteId !== '') {
            $query->where('ur.' . UrlRewrite::schema_fields_WEBSITE_ID, (int)$websiteId);
        }
        
        $url = $query->find()->fetch();
        $this->assign('url', $url);
        $this->assign('current_website_id', UrlRewrite::getCurrentWebsiteId());
        return $this->fetch();
    }

    /**
     * @return void
     */
    public function getDelete(): void
    {
        $rewrite_id = $this->request->getGet('rewrite_id', '');
        /**@var UrlRewrite $urlRewrite */
        $urlRewrite = ObjectManager::getInstance(UrlRewrite::class);
        try {
            $urlRewrite->where($urlRewrite::schema_fields_ID, $rewrite_id)->delete();
            $this->getMessageManager()->addError(__('删除成功！'));
        } catch (Exception $exception) {
            $this->getMessageManager()->addError(__('删除失败！') . (DEV ? $exception->getMessage() : ''));
        }
        $this->redirect($this->_url->getBackendUrl('url-manager/backend/rewriter'));
    }
}
