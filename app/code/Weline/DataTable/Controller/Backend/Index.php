<?php

namespace Weline\DataTable\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Index extends BackendController
{
    /**
     * 数据表格管理首页
     */
    public function index()
    {
        $this->assign('title', '数据表格管理');
        $this->assign('description', 'Weline DataTable 模块提供强大的数据表格功能');
        
        // 获取可用的数据表格配置
        $this->assign('available_tables', [
            'users' => [
                'name' => '用户管理',
                'model' => 'Weline\DataTable\Model\TestUser',
                'description' => '用户数据管理表格'
            ],
            'products' => [
                'name' => '产品管理',
                'model' => 'Weline\DataTable\Model\TestProduct',
                'description' => '产品数据管理表格'
            ],
            'orders' => [
                'name' => '订单管理',
                'model' => 'Weline\DataTable\Model\TestOrder',
                'description' => '订单数据管理表格'
            ]
        ]);
        
        return $this->fetch();
    }

    /**
     * 数据表格配置管理
     */
    public function config()
    {
        $this->assign('title', '数据表格配置');
        return $this->fetch();
    }

    /**
     * 数据表格模板管理
     */
    public function templates()
    {
        $this->assign('title', '数据表格模板');
        return $this->fetch();
    }
} 