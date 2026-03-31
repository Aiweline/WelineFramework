<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Backend\Customer;

use WeShop\Customer\Model\Customer;
use Weline\Admin\Controller\BaseController;

/**
 * 客户详情管理后台控制器
 */
class View extends BaseController
{
    /**
     * 客户详情页
     */
    public function index(): string
    {
        $customerId = (int) $this->getRequest()->getParam('id', 0);

        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        // 获取客户信息
        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            $this->getResponse()->setHttpResponseCode(404);
            $this->assign('error_message', (string) __('Customer not found or has been deleted.'));
            return $this->fetch('customer/view');
        }

        $this->assign('customer', $customer);
        $this->assign('customer_data', $customer->getData());

        return $this->fetch('customer/view');
    }

    /**
     * 编辑客户
     */
    public function edit(): string
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id', 0);

        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        // 获取客户信息
        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->fetchJson([
                'status' => 404,
                'message' => (string) __('Customer not found.'),
                'title' => (string) __('Error')
            ]);
        }

        // 处理更新
        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost();

            $customer->setData($data);
            $customer->save();

            return $this->fetchJson([
                'status' => 200,
                'message' => (string) __('Customer information has been updated.'),
                'title' => (string) __('Success')
            ]);
        }

        $this->assign('customer', $customer);
        $this->assign('customer_data', $customer->getData());

        return $this->fetch('customer/view');
    }

    /**
     * 禁用客户
     */
    public function disable(): string
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id', 0);

        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->fetchJson([
                'status' => 404,
                'message' => (string) __('Customer not found.'),
                'title' => (string) __('Error')
            ]);
        }

        $customer->setData('is_active', 0);
        $customer->save();

        return $this->fetchJson([
            'status' => 200,
            'message' => (string) __('Customer has been disabled.'),
            'title' => (string) __('Success')
        ]);
    }

    /**
     * 启用客户
     */
    public function enable(): string
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id', 0);

        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->fetchJson([
                'status' => 404,
                'message' => (string) __('Customer not found.'),
                'title' => (string) __('Error')
            ]);
        }

        $customer->setData('is_active', 1);
        $customer->save();

        return $this->fetchJson([
            'status' => 200,
            'message' => (string) __('Customer has been enabled.'),
            'title' => (string) __('Success')
        ]);
    }

    /**
     * 删除客户
     */
    public function delete(): string
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id', 0);

        /** @var Customer $customerModel */
        $customerModel = \Weline\Framework\Manager\ObjectManager::getInstance(Customer::class);

        $customer = $customerModel->clear()->find($customerId);

        if (!$customer->getId()) {
            return $this->fetchJson([
                'status' => 404,
                'message' => (string) __('Customer not found.'),
                'title' => (string) __('Error')
            ]);
        }

        $customer->delete();

        return $this->fetchJson([
            'status' => 200,
            'message' => (string) __('Customer has been deleted.'),
            'title' => (string) __('Success')
        ]);
    }
}
