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
use Weline\Framework\Manager\ObjectManager;
use Weline\UrlManager\Model\UrlManager;
use Weline\UrlManager\Model\UrlRewrite;

class Rewriter extends \Weline\Framework\App\Controller\BackendController
{
    public function get()
    {
        /**@var UrlRewrite $urlRewriteModel */
        $urlRewriteModel = ObjectManager::getInstance(UrlRewrite::class);
//        dd($urlRewriteModel);
        $rewrites = $urlRewriteModel->fields('main_table.*,main_table.path as rewrite_path,um.url_id,um.path,um.is_deleted')
                                    ->joinModel(UrlManager::class, 'um', 'main_table.url_id=um.url_id')
                                    ->pagination()
                                    ->select()
                                    ->fetch();
        $this->assign('rewrites', $rewrites->getItems());
        $this->assign('pagination', $rewrites->getPagination());
        return $this->fetch();
    }

    public function post()
    {
        $data = $this->request->getPost();
        if (!isset($data['path'])) {
            $origin_path_arr = explode('::', $data['origin_path']);
            $data['path']    = array_shift($origin_path_arr);
        } else {
            $data['url_identify'] = md5($data['path']);
        }
        /**@var UrlRewrite $urlRewriter */
        $urlRewriter = ObjectManager::getInstance(UrlRewrite::class);
        $urlRewriter->setData($data);
        try {
            $urlRewriter->save();
        } catch (\ReflectionException|Exception|ModelException $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        $this->getMessageManager()->addSuccess(__('重写成功！'));
//        $this->redirect($this->request->getBackendUrl('/url-manager/backend/url/listing'));
        $this->redirect($this->_url->getBackendUrl('url-manager/backend/rewriter'));
    }

    public function form()
    {
        $uri_identify = $this->request->getGet('identify', '');
        /**@var UrlManager $urlManager */
        $urlManager = ObjectManager::getInstance(UrlManager::class);
        $url        = $urlManager->where($urlManager::fields_IDENTIFY, $uri_identify)
                                 ->fields('main_table.*,ur.rewrite as rewrite_path')
                                 ->joinModel(UrlRewrite::class, 'ur', 'main_table.identify=ur.url_identify', 'left')
                                 ->find()->fetch();
        $this->assign('url', $url);
        return $this->fetch();
    }

    /**
     * @throws Exception
     * @throws \ReflectionException
     * @throws Core
     */
    public function getDelete()
    {
        $rewrite_id = $this->request->getGet('rewrite_id', '');
        /**@var UrlRewrite $urlRewrite */
        $urlRewrite = ObjectManager::getInstance(UrlRewrite::class);
        try {
            $urlRewrite->where($urlRewrite::fields_ID, $rewrite_id)->delete();
            $this->getMessageManager()->addError(__('删除成功！'));
        } catch (Exception $exception) {
            $this->getMessageManager()->addError(__('删除失败！') . (DEV ? $exception->getMessage() : ''));
        }
        $this->redirect($this->_url->getBackendUrl('url-manager/backend/rewriter'));
    }
}
