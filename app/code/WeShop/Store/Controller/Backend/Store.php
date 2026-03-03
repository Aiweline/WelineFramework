<?php

namespace WeShop\Store\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale;
use Weline\Websites\Model\Website;

#[Acl('WeShop_Store::manager', '店铺管理', 'ri-settings-2-fill', '店铺管理', 'WeShop_Store::main')]
class Store extends BackendController
{
    private \WeShop\Store\Model\Store $store;
    private Website $website;

    public function __construct(
        \WeShop\Store\Model\Store $store,
        Website $website
    )
    {
        $this->store = $store;
        $this->website = $website;
    }

    #[Acl('WeShop_Store::listing', '店铺列表', 'mdi mdi-format-list-bulleted-squarel', '店铺管理')]
    public function index()
    {
        // 搜索
        $search = $this->request->getGet('q');
        $page = $this->request->getGet('page');
        $page = $page ?: 1;
        $pageSize = $this->request->getGet('pageSize');
        $pageSize = $pageSize ?: 10;
        if ($search) {
            $this->store->where('name', 'like', "%$search%");
        }
        $listing = $this->store->loadLocalDescription()->pagination($page, $pageSize)->select()->fetch();
        $listings = $listing->getItems();
        /**@var \WeShop\Store\Model\Store $listing */
        foreach ($listings as &$listing) {
            $listing = $listing->loadLocalName($listing::fields_LOCAL, 'local_name');
        }
        $this->assign('stores', $listings);
        $this->assign('pagination', $listing->getPagination());
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        return $this->fetch();
    }

    #[Acl('WeShop_Store::add_page', '店铺添加', 'mdi mdi-pen-plus', '店铺添加')]
    public function getAdd()
    {
        $isIframe = $this->request->getGet('isIframe') || $this->request->getPost('isIframe');
        
        $this->assign('action', $this->_url->getCurrentUrl());
        # 所有已安装区域
        $this->assign('locals', self::getLocals());
        # 获取所有网站列表
        $websites = $this->website->select()->fetchArray();
        $this->assign('websites', $websites);
        # 新增时提供空的 store 对象，避免模板中 store.image 等属性引用报错
        $this->assign('store', ['image' => '']);
        $this->assign('isIframe', $isIframe);
        return $this->fetch('form');
    }

    static function getLocals(): array
    {
        /**@var Locale $local */
        $local = ObjectManager::getInstance(Locale::class);
        return $local->where($local::fields_IS_INSTALL, 1)
            ->where($local::fields_IS_ACTIVE, 1)
            ->where(Locale\Name::fields_DISPLAY_LOCALE_CODE, \Weline\Framework\Http\Cookie::getLangLocal())
            ->joinModel(Locale\Name::class, 'name', 'main_table.code=name.locale_code')
            ->select()->fetchArray();
    }

    #[Acl('WeShop_Store::add_post', '店铺添加', 'mdi mdi-pen-plus', '店铺添加')]
    public function postAdd()
    {
        $data = $this->request->getPost();
        $isIframe = $this->request->getGet('isIframe') || $this->request->getPost('isIframe');
        
        try {
            $this->store->setModelFieldsData($data)
                ->save();
            
            // 如果是 iframe 模式，重定向到 offcanvas 成功页面
            if ($isIframe) {
                $this->redirect('component/offcanvas/success', [
                    'msg' => __('添加成功！'),
                    'time' => 2,
                    'reload' => 1
                ]);
                return;
            }
            
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'status' => true,
                    'success' => true,
                    'message' => __('添加成功！'),
                    'id' => $this->store->getId()
                ]);
            }
            
            $this->getMessageManager()->addSuccess(__('添加成功！'));
            $this->redirect('*/backend/store/edit', ['id' => $this->store->getId()]);
        } catch (\Exception $e) {
            // 如果是 iframe 模式，重定向到 offcanvas 错误页面
            if ($isIframe) {
                $this->redirect('component/offcanvas/error', [
                    'msg' => __('添加商铺出现问题！') . ': ' . $e->getMessage(),
                    'time' => 5,
                    'reload' => 0
                ]);
                return;
            }
            
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'status' => false,
                    'success' => false,
                    'message' => __('添加商铺出现问题！') . ': ' . $e->getMessage()
                ]);
            }
            
            $this->getMessageManager()->addError(__('添加商铺出现问题！'));
            $this->getMessageManager()->addException($e);
            $this->redirect();
        }
    }

    #[Acl('WeShop_Store::edit', '店铺编辑', 'ri-edit-2-fill', '店铺编辑')]
    public function edit()
    {
        $isIframe = $this->request->getGet('isIframe') || $this->request->getPost('isIframe');
        
        if ($this->request->isGet()) {
            $id = $this->request->getGet('id');
            if (!$id) {
                throw new \Weline\Framework\Http\ResponseTerminateException(400, __('参数错误！'), ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            $store = $this->store->load($id);
            if (!$store->getId()) {
                $this->getMessageManager()->addError(__('商铺不存在！'));
            }
            $this->assign('locals', self::getLocals());
            # 获取所有网站列表
            $websites = $this->website->select()->fetchArray();
            $this->assign('websites', $websites);
            $this->assign('action', $this->_url->getCurrentUrl());
            $this->assign('store', $store);
            $this->assign('isIframe', $isIframe);
            return $this->fetch('form');
        }
        if (!$this->request->isPost()) {
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'status' => false,
                    'success' => false,
                    'message' => __('请求错误！')
                ]);
            }
            $this->getMessageManager()->addError(__('请求错误！'));
            $this->redirect();
        }

        $data = $this->request->getPost();
        try {
            $this->store->setModelFieldsData($data)
                ->save();
            
            // 如果是 iframe 模式，重定向到 offcanvas 成功页面
            if ($isIframe) {
                $this->redirect('component/offcanvas/success', [
                    'msg' => __('编辑商铺成功！'),
                    'time' => 2,
                    'reload' => 1
                ]);
                return;
            }
            
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'status' => true,
                    'success' => true,
                    'message' => __('编辑商铺成功！'),
                    'id' => $this->store->getId()
                ]);
            }
            
            $this->getMessageManager()->addSuccess(__('编辑商铺成功！'));
            $this->redirect('*/backend/store/edit', ['id' => $this->store->getId()]);
        } catch (\Exception $e) {
            // 如果是 iframe 模式，重定向到 offcanvas 错误页面
            if ($isIframe) {
                $this->redirect('component/offcanvas/error', [
                    'msg' => __('编辑商铺出现问题！') . ': ' . $e->getMessage(),
                    'time' => 5,
                    'reload' => 0
                ]);
                return;
            }
            
            // 如果是 AJAX 请求，返回 JSON
            if ($this->request->isAjax() || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
                return $this->fetchJson([
                    'status' => false,
                    'success' => false,
                    'message' => __('编辑商铺出现问题！') . ': ' . $e->getMessage()
                ]);
            }
            
            $this->getMessageManager()->addError(__('编辑商铺出现问题！'));
            $this->getMessageManager()->addException($e);
            $this->redirect();
        }
    }

    #[Acl('WeShop_Store::code_exit', '店铺编码查询', 'mdi mdi-file-find', '店铺编码查询')]
    public function ajaxCodeExist(): string
    {
        $code = $this->request->getPost('code');
        $store = $this->store->where('code', $code)->find()->fetch();
        if ($store->getId()) {
            return json_encode(false);
        } else {
            return json_encode(true);
        }
    }

    #[Acl('WeShop_Store::delete', '店铺删除', 'mdi mdi-delete', '店铺删除')]
    public function postDelete(): string
    {
        $id = $this->request->getPost('id');
        $store = $this->store->where($this->store::fields_ID, $id)->find()->fetch();
        if ($store->getId()) {
            try {
                $store->delete();
                return $this->fetchJson([
                    'status' => true,
                    'message' => __('删除成功！')
                ]);
            } catch (\ReflectionException|Core|Exception $e) {
                return $this->fetchJson([
                    'status' => false,
                    'message' => $e->getMessage()
                ]);
            }
        } else {
            return $this->fetchJson([
                'status' => false,
                'message' => __('店铺不存在！')
            ]);
        }
    }
}
