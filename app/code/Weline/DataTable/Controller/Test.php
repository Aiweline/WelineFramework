<?php

namespace Weline\DataTable\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\DataTable\Model\TestUser;
use Weline\DataTable\Model\TestProduct;
use Weline\DataTable\Model\TestOrder;

class Test extends BackendController
{
    /**
     * 测试用户数据
     */
    public function testUsers()
    {
        $testUser = new TestUser();
        
        // 获取测试数据
        $testData = $testUser->getTestData();
        
        // 插入测试数据
        foreach ($testData as $data) {
            $testUser->clearData()->setData($data)->save();
        }
        
        return $this->success('测试用户数据已创建', [
            'count' => count($testData),
            'table' => $testUser->getTable()
        ]);
    }

    /**
     * 测试产品数据
     */
    public function testProducts()
    {
        $testProduct = new TestProduct();
        
        // 获取测试数据
        $testData = $testProduct->getTestData();
        
        // 插入测试数据
        foreach ($testData as $data) {
            $testProduct->clearData()->setData($data)->save();
        }
        
        return $this->success('测试产品数据已创建', [
            'count' => count($testData),
            'table' => $testProduct->getTable()
        ]);
    }

    /**
     * 测试订单数据
     */
    public function testOrders()
    {
        $testOrder = new TestOrder();
        
        // 获取测试数据
        $testData = $testOrder->getTestData();
        
        // 插入测试数据
        foreach ($testData as $data) {
            $testOrder->clearData()->setData($data)->save();
        }
        
        return $this->success('测试订单数据已创建', [
            'count' => count($testData),
            'table' => $testOrder->getTable()
        ]);
    }

    /**
     * 创建所有测试数据
     */
    public function createAllTestData()
    {
        $results = [];
        
        // 创建用户测试数据
        $testUser = new TestUser();
        $userData = $testUser->getTestData();
        foreach ($userData as $data) {
            $testUser->clearData()->setData($data)->save();
        }
        $results['users'] = [
            'count' => count($userData),
            'table' => $testUser->getTable()
        ];
        
        // 创建产品测试数据
        $testProduct = new TestProduct();
        $productData = $testProduct->getTestData();
        foreach ($productData as $data) {
            $testProduct->clearData()->setData($data)->save();
        }
        $results['products'] = [
            'count' => count($productData),
            'table' => $testProduct->getTable()
        ];
        
        // 创建订单测试数据
        $testOrder = new TestOrder();
        $orderData = $testOrder->getTestData();
        foreach ($orderData as $data) {
            $testOrder->clearData()->setData($data)->save();
        }
        $results['orders'] = [
            'count' => count($orderData),
            'table' => $testOrder->getTable()
        ];
        
        return $this->success('所有测试数据已创建', $results);
    }

    /**
     * 查看测试数据
     */
    public function viewTestData()
    {
        $type = $this->request->getParam('type', 'users');
        
        switch ($type) {
            case 'users':
                $model = new TestUser();
                $data = $model->select()->fetchArray();
                break;
            case 'products':
                $model = new TestProduct();
                $data = $model->select()->fetchArray();
                break;
            case 'orders':
                $model = new TestOrder();
                $data = $model->select()->fetchArray();
                break;
            default:
                return $this->error('无效的数据类型');
        }
        
        return $this->success('测试数据查询成功', [
            'type' => $type,
            'count' => count($data),
            'data' => $data
        ]);
    }

    /**
     * 清空测试数据
     */
    public function clearTestData()
    {
        $type = $this->request->getParam('type', 'all');
        
        $results = [];
        
        if ($type === 'all' || $type === 'users') {
            $testUser = new TestUser();
            $testUser->query("TRUNCATE TABLE {$testUser->getTable()}");
            $results['users'] = '用户测试数据已清空';
        }
        
        if ($type === 'all' || $type === 'products') {
            $testProduct = new TestProduct();
            $testProduct->query("TRUNCATE TABLE {$testProduct->getTable()}");
            $results['products'] = '产品测试数据已清空';
        }
        
        if ($type === 'all' || $type === 'orders') {
            $testOrder = new TestOrder();
            $testOrder->query("TRUNCATE TABLE {$testOrder->getTable()}");
            $results['orders'] = '订单测试数据已清空';
        }
        
        return $this->success('测试数据已清空', $results);
    }

    /**
     * 获取模型选项
     */
    public function getModelOptions()
    {
        $type = $this->request->getParam('type');
        
        switch ($type) {
            case 'user_status':
                $model = new TestUser();
                $options = $model->getStatusOptions();
                break;
            case 'user_gender':
                $model = new TestUser();
                $options = $model->getGenderOptions();
                break;
            case 'product_status':
                $model = new TestProduct();
                $options = $model->getStatusOptions();
                break;
            case 'product_featured':
                $model = new TestProduct();
                $options = $model->getFeaturedOptions();
                break;
            case 'order_payment_status':
                $model = new TestOrder();
                $options = $model->getPaymentStatusOptions();
                break;
            case 'order_status':
                $model = new TestOrder();
                $options = $model->getOrderStatusOptions();
                break;
            case 'order_payment_method':
                $model = new TestOrder();
                $options = $model->getPaymentMethodOptions();
                break;
            default:
                return $this->error('无效的选项类型');
        }
        
        return $this->success('选项获取成功', [
            'type' => $type,
            'options' => $options
        ]);
    }
} 