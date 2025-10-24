<?php

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\Model\Template;

class MultiModel extends BackendController
{
    /**
     * 多模型JOIN查询测试页面
     */
    public function index()
    {
        $this->assign('title', '多模型JOIN查询测试');
        $this->assign('description', '测试DataTable组件的多模型和JOIN查询功能');
        
        return $this->fetch('Weline_DataTable::test/multi-model-test.phtml');
    }

    /**
     * 基本多模型查询测试
     */
    public function basic()
    {
        $this->assign('title', '基本多模型查询测试');
        $this->assign('description', '测试基本的多模型查询功能，不包含JOIN');
        
        return $this->fetch('Weline_DataTable::test/basic-multi-model.phtml');
    }

    /**
     * JOIN查询测试
     */
    public function join()
    {
        $this->assign('title', 'JOIN查询测试');
        $this->assign('description', '测试多模型JOIN查询功能');
        
        return $this->fetch('Weline_DataTable::test/join-query.phtml');
    }

    /**
     * 复杂JOIN查询测试
     */
    public function complex()
    {
        $this->assign('title', '复杂JOIN查询测试');
        $this->assign('description', '测试复杂的多表JOIN查询');
        
        return $this->fetch('Weline_DataTable::test/complex-join.phtml');
    }

    /**
     * 手动配置字段测试
     */
    public function manual()
    {
        $this->assign('title', '手动配置字段测试');
        $this->assign('description', '测试手动配置多模型字段');
        
        return $this->fetch('Weline_DataTable::test/manual-config.phtml');
    }

    /**
     * 获取测试数据
     */
    public function getTestData()
    {
        try {
            // 模拟一些测试数据
            $data = [
                'admin' => [
                    ['admin_id' => 1, 'username' => 'admin1', 'email' => 'admin1@test.com', 'status' => 1, 'store_id' => 1],
                    ['admin_id' => 2, 'username' => 'admin2', 'email' => 'admin2@test.com', 'status' => 1, 'store_id' => 2],
                    ['admin_id' => 3, 'username' => 'admin3', 'email' => 'admin3@test.com', 'status' => 0, 'store_id' => 1],
                ],
                'store' => [
                    ['store_id' => 1, 'name' => '测试店铺1', 'status' => 1],
                    ['store_id' => 2, 'name' => '测试店铺2', 'status' => 1],
                    ['store_id' => 3, 'name' => '测试店铺3', 'status' => 0],
                ],
                'config' => [
                    ['config_id' => 1, 'key' => 'site_name', 'value' => '测试站点', 'scope' => 'admin1'],
                    ['config_id' => 2, 'key' => 'site_url', 'value' => 'https://test.com', 'scope' => 'admin2'],
                    ['config_id' => 3, 'key' => 'admin_email', 'value' => 'admin@test.com', 'scope' => 'admin1'],
                ]
            ];

            return $this->success('获取测试数据成功', $data);
        } catch (\Exception $e) {
            return $this->error('获取测试数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建测试数据
     */
    public function createTestData()
    {
        try {
            // 这里可以创建一些测试数据到数据库中
            // 为了演示，我们只返回成功消息
            return $this->success('测试数据创建成功');
        } catch (\Exception $e) {
            return $this->error('创建测试数据失败: ' . $e->getMessage());
        }
    }
} 