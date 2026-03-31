<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Backend\Customer;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Customer\Model\Customer;

/**
 * 客户详情管理后台控制器
 */
class View extends BackendController
{
    /**
     * 客户详情页
     */
    public function index()
    {
        $customerId = $this->getRequest()->getParam('id');

        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);

        // 获取客户信息
        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            $this->getResponse()->setHttpResponseCode(404);
            $this->assign('error_message', __('客户不存在或已被删除'));
            return $this->fetch('customer/view');
        }

        $this->assign('customer', $customer);
        $this->assign('customer_data', $customer->getData());

        return $this->fetch('customer/view');
    }

    /**
     * 编辑客户
     */
    public function edit()
    {
        $customerId = $this->getRequest()->getParam('customer_id');

        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);

        // 获取客户信息
        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->json([
                'status' => 404,
                'message' => __('客户不存在'),
                'title' => __('错误')
            ]);
        }

        // 处理更新
        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost();

            $customer->setData($data);
            $customer->save();

            return $this->json([
                'status' => 200,
                'message' => __('客户信息已更新'),
                'title' => __('成功')
            ]);
        }

        $this->assign('customer', $customer);
        $this->assign('customer_data', $customer->getData());

        return $this->fetch('customer/view');
    }

    /**
     * 禁用客户
     */
    public function disable()
    {
        $customerId = $this->getRequest()->getParam('customer_id');

        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->json([
                'status' => 404,
                'message' => __('客户不存在'),
                'title' => __('错误')
            ]);
        }

        $customer->setData('is_active', 0);
        $customer->save();

        return $this->json([
            'status' => 200,
            'message' => __('客户已禁用'),
            'title' => __('成功')
        ]);
    }

    /**
     * 启用客户
     */
    public function enable()
    {
        $customerId = $this->getRequest()->getParam('customer_id');

        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->json([
                'status' => 404,
                'message' => __('客户不存在'),
                'title' => __('错误')
            ]);
        }

        $customer->setData('is_active', 1);
        $customer->save();

        return $this->json([
            'status' => 200,
            'message' => __('客户已启用'),
            'title' => __('成功')
        ]);
    }

    /**
     * 删除客户
     */
    public function delete()
    {
        $customerId = $this->getRequest()->getParam('customer_id');

        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->json([
                'status' => 404,
                'message' => __('客户不存在'),
                'title' => __('错误')
            ]);
        }

        $customer->delete();

        return $this->json([
            'status' => 200,
            'message' => __('客户已删除'),
            'title' => __('成功')
        ]);
    }
}
