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
use Weline\Shipping\Service\DeliveryAddressService;
use Weline\Frontend\Model\FrontendUser;

#[Acl('Weline_Shipping::delivery_address', '运送地址管理', 'mdi-truck-delivery', '运送地址管理', 'Weline_Backend::business_module')]
class DeliveryAddress extends BackendController
{
    private DeliveryAddressService $service;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->service = $objectManager->getInstance(DeliveryAddressService::class);
    }

    /**
     * 地址管理列表页
     */
    #[Acl('Weline_Shipping::delivery_address_index', '查看运送地址', 'mdi-format-list-bulleted', '查看运送地址列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        $customerId = $this->request->getParam('customer_id');
        $isEnabled = $this->request->getParam('is_enabled');
        
        $filters = [];
        if ($keyword) {
            $filters['keyword'] = $keyword;
        }
        if ($customerId) {
            $filters['customer_id'] = (int)$customerId;
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
        
        // 获取所有客户列表用于筛选
        try {
            /** @var FrontendUser $customerModel */
            $customerModel = ObjectManager::getInstance(FrontendUser::class, [], false);
            $customers = $customerModel->reset()
                ->order(FrontendUser::fields_ID, 'DESC')
                ->pagination(1, 1000) // 获取前1000个客户
                ->select()
                ->fetch()
                ->getItems();
            
            // 格式化客户数据
            $formattedCustomers = [];
            foreach ($customers as $customer) {
                $data = is_array($customer) ? $customer : $customer->getData();
                $formattedCustomers[] = [
                    'id' => $data[FrontendUser::fields_ID] ?? $data['user_id'] ?? 0,
                    'customer_id' => $data[FrontendUser::fields_ID] ?? $data['user_id'] ?? 0,
                    'name' => $data[FrontendUser::fields_username] ?? $data['username'] ?? '',
                    'email' => $data['email'] ?? '',
                    'username' => $data[FrontendUser::fields_username] ?? $data['username'] ?? '',
                ];
            }
            $customers = $formattedCustomers;
        } catch (\Throwable $e) {
            // 如果获取客户列表失败，使用空数组
            $customers = [];
        }
        
        $this->assign('addresses', $addresses);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('keyword', $keyword);
        $this->assign('customer_id_filter', $customerId);
        $this->assign('is_enabled', $isEnabled);
        $this->assign('customers', $customers);
        
        return $this->fetch();
    }

    /**
     * 地址编辑表单页
     */
    #[Acl('Weline_Shipping::delivery_address_edit', '编辑运送地址', 'mdi-pencil', '编辑运送地址')]
    public function edit()
    {
        $id = $this->request->getParam('id');
        $address = null;
        
        if ($id) {
            $addressModel = $this->service->getById((int)$id);
            if (!$addressModel || !$addressModel->getId()) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
            $address = $addressModel->getData();
        }
        
        // 获取所有客户列表用于选择
        try {
            /** @var FrontendUser $customerModel */
            $customerModel = ObjectManager::getInstance(FrontendUser::class, [], false);
            $customers = $customerModel->reset()
                ->order(FrontendUser::fields_ID, 'DESC')
                ->pagination(1, 1000) // 获取前1000个客户
                ->select()
                ->fetch()
                ->getItems();
            
            // 格式化客户数据
            $formattedCustomers = [];
            foreach ($customers as $customer) {
                $data = is_array($customer) ? $customer : $customer->getData();
                $formattedCustomers[] = [
                    'id' => $data[FrontendUser::fields_ID] ?? $data['user_id'] ?? 0,
                    'customer_id' => $data[FrontendUser::fields_ID] ?? $data['user_id'] ?? 0,
                    'name' => $data[FrontendUser::fields_username] ?? $data['username'] ?? '',
                    'email' => $data['email'] ?? '',
                    'username' => $data[FrontendUser::fields_username] ?? $data['username'] ?? '',
                ];
            }
            $customers = $formattedCustomers;
        } catch (\Throwable $e) {
            // 如果获取客户列表失败，使用空数组
            $customers = [];
        }
        
        $this->assign('address', $address);
        $this->assign('customers', $customers);
        return $this->fetch();
    }

    /**
     * 保存地址
     */
    #[Acl('Weline_Shipping::delivery_address_save', '保存运送地址', 'mdi-content-save', '保存运送地址')]
    public function save()
    {
        $data = $this->request->getPost();
        $id = $data['delivery_address_id'] ?? null;
        $customerId = $data['customer_id'] ?? null;
        
        if (!$customerId && !$id) {
            $this->getMessageManager()->addError(__('客户ID不能为空'));
            $this->redirect('*/edit' . ($id ? '?id=' . $id : ''));
            return;
        }
        
        try {
            if ($id) {
                $addressModel = $this->service->getById((int)$id);
                if (!$addressModel || !$addressModel->getId()) {
                    $this->getMessageManager()->addError(__('地址不存在'));
                    $this->redirect('*/edit' . ($id ? '?id=' . $id : ''));
                    return;
                }
                $customerId = (int)$addressModel->getData('customer_id');
                $this->service->update((int)$id, $customerId, $data);
                $message = __('更新成功');
            } else {
                if (!$customerId) {
                    $this->getMessageManager()->addError(__('客户ID不能为空'));
                    $this->redirect('*/edit');
                    return;
                }
                $this->service->create((int)$customerId, $data);
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
    #[Acl('Weline_Shipping::delivery_address_delete', '删除运送地址', 'mdi-delete', '删除运送地址')]
    public function delete()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $addressModel = $this->service->getById((int)$id);
            if (!$addressModel || !$addressModel->getId()) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
            $customerId = (int)$addressModel->getData('customer_id');
            $this->service->delete((int)$id, $customerId);
            $this->getMessageManager()->addSuccess(__('删除成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }

    /**
     * 设置默认地址
     */
    #[Acl('Weline_Shipping::delivery_address_set_default', '设置默认运送地址', 'mdi-star', '设置默认运送地址')]
    public function setDefault()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $addressModel = $this->service->getById((int)$id);
            if (!$addressModel || !$addressModel->getId()) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
            $customerId = (int)$addressModel->getData('customer_id');
            $this->service->setDefault((int)$id, $customerId);
            $this->getMessageManager()->addSuccess(__('设置成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }

    /**
     * 启用地址
     */
    #[Acl('Weline_Shipping::delivery_address_enable', '启用运送地址', 'mdi-check-circle', '启用运送地址')]
    public function enable()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $addressModel = $this->service->getById((int)$id);
            if (!$addressModel || !$addressModel->getId()) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
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
    #[Acl('Weline_Shipping::delivery_address_disable', '禁用运送地址', 'mdi-cancel', '禁用运送地址')]
    public function disable()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $addressModel = $this->service->getById((int)$id);
            if (!$addressModel || !$addressModel->getId()) {
                $this->getMessageManager()->addError(__('地址不存在'));
                $this->redirect('*/index');
                return;
            }
            $this->service->setEnabled((int)$id, false);
            $this->getMessageManager()->addSuccess(__('禁用成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/index');
    }
}

