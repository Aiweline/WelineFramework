<?php

namespace WeShop\Store\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;

#[Acl('WeShop_Store::manager', '店铺管理', 'ri-settings-2-fill', '店铺管理', 'WeShop_Store::main')]
class Store extends BackendController
{
    private \WeShop\Store\Model\Store $store;

    public function __construct(
        \WeShop\Store\Model\Store $store,
    )
    {
        $this->store = $store;
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
        $listing = $this->store->addLocalDescription()->pagination($page, $pageSize)->select()->fetch();
        $listings = $listing->getItems();
        /**@var \WeShop\Store\Model\Store $listing */
        foreach ($listings as &$listing) {
            $listing = $listing->loadLocalName();
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
        $this->assign('action', $this->_url->getCurrentUrl());
        # 所有已安装区域
        $this->assign('locals', self::getLocals());
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
    public function postAdd(): void
    {
        $data = $this->request->getPost();
        try {
            $this->store->setModelFieldsData($data)
                ->save();
            $this->getMessageManager()->addSuccess(__('添加成功！'));
            $this->redirect('*/backend/store/edit', ['id' => $this->store->getId()]);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('添加商铺出现问题！'));
            $this->getMessageManager()->addException($e);
            $this->redirect();
        }
    }

    #[Acl('WeShop_Store::edit', '店铺编辑', 'ri-edit-2-fill', '店铺编辑')]
    public function edit()
    {
        if ($this->request->isGet()) {
            $id = $this->request->getGet('id');
            if (!$id) {
                die(__('参数错误！'));
            }
            $store = $this->store->load($id);
            if (!$store->getId()) {
                $this->getMessageManager()->addError(__('商铺不存在！'));
            }
            $this->assign('locals', self::getLocals());
            $this->assign('action', $this->_url->getCurrentUrl());
            $this->assign('store', $store);
            return $this->fetch('form');
        }
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('请求错误！'));
            $this->redirect();
        }

        $data = $this->request->getPost();
        try {
            $this->store->setModelFieldsData($data)
                ->save();
            $this->getMessageManager()->addSuccess(__('编辑商铺成功！'));
            $this->redirect('*/backend/store/edit', ['id' => $this->store->getId()]);
        } catch (\Exception $e) {
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
