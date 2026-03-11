<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingAddressService;

#[Acl('Weline_Shipping::shipping_address', '发货地址管理', 'mdi-map-marker', '发货地址管理', 'Weline_Backend::shipping_group')]
class ShippingAddress extends BackendController
{
    private ShippingAddressService $service;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->service = $objectManager->getInstance(ShippingAddressService::class);
    }

    /**
     * 地址管理列表页
     */
    #[Acl('Weline_Shipping::shipping_address_index', '查看发货地址', 'mdi-format-list-bulleted', '查看发货地址列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        $isEnabled = $this->request->getParam('is_enabled');
        
        $filters = [];
        if ($keyword) {
            $filters['keyword'] = $keyword;
        }
        if ($isEnabled !== null && $isEnabled !== '') {
            $filters['is_enabled'] = (int)$isEnabled;
        }
        
        $addresses = $this->service->getList($filters);
        $total = count($addresses);
        $totalPages = (int)ceil($total / $limit);
        
        // 分页处理
        $offset = ($page - 1) * $limit;
        $addresses = array_slice($addresses, $offset, $limit);
        
        $this->assign('addresses', $addresses);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('keyword', $keyword);
        $this->assign('is_enabled', $isEnabled);
        
        return $this->fetch();
    }

    /**
     * 地址编辑表单页
     */
    #[Acl('Weline_Shipping::shipping_address_edit', '编辑发货地址', 'mdi-pencil', '编辑发货地址')]
    public function edit()
    {
        $id = $this->request->getParam('id');
        $address = null;
        
        if ($id) {
            $address = $this->service->getById((int)$id);
            if (!$address) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
        }
        
        $this->assign('address', $address);
        return $this->fetch();
    }

    /**
     * 保存地址
     */
    #[Acl('Weline_Shipping::shipping_address_save', '保存发货地址', 'mdi-content-save', '保存发货地址')]
    public function save()
    {
        $data = $this->request->getPost();
        $id = $data['shipping_address_id'] ?? null;
        
        try {
            if ($id) {
                $this->service->update((int)$id, $data);
                $message = __('更新成功');
            } else {
                $this->service->create($data);
                $message = __('创建成功');
            }
            
            $this->getMessageManager()->addSuccess($message);
            $this->redirect('*/index');
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/edit' . ($id ? '?id=' . $id : ''));
        }
    }

    /**
     * 删除地址
     */
    #[Acl('Weline_Shipping::shipping_address_delete', '删除发货地址', 'mdi-delete', '删除发货地址')]
    public function delete()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->delete((int)$id);
            $this->getMessageManager()->addSuccess(__('删除成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }

    /**
     * 设置默认地址
     */
    #[Acl('Weline_Shipping::shipping_address_set_default', '设置默认发货地址', 'mdi-star', '设置默认发货地址')]
    public function setDefault()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->setDefault((int)$id);
            $this->getMessageManager()->addSuccess(__('设置成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }

    /**
     * 启用地址
     */
    #[Acl('Weline_Shipping::shipping_address_enable', '启用发货地址', 'mdi-check-circle', '启用发货地址')]
    public function enable()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->setEnabled((int)$id, true);
            $this->getMessageManager()->addSuccess(__('启用成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }

    /**
     * 禁用地址
     */
    #[Acl('Weline_Shipping::shipping_address_disable', '禁用发货地址', 'mdi-cancel', '禁用发货地址')]
    public function disable()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->service->setEnabled((int)$id, false);
            $this->getMessageManager()->addSuccess(__('禁用成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }
}

