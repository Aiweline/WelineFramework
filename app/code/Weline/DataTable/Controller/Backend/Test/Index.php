<?php

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Framework\App\Controller\BackendController;

class Index extends BackendController
{
    /**
     * DataTable测试主页面
     */
    public function index()
    {
        $this->assign('title', 'DataTable 功能测试中心');
        $this->assign('description', '测试DataTable组件的各种功能特性');
        
        return $this->fetch('Weline_DataTable::test/index.phtml');
    }

    /**
     * 基本表格功能测试
     */
    public function basic()
    {
        $this->assign('title', '基本表格功能测试');
        $this->assign('description', '测试DataTable的基本表格显示功能');
        
        return $this->fetch('Weline_DataTable::test/basic-table.phtml');
    }

    /**
     * 表单功能测试
     */
    public function form()
    {
        $this->assign('title', '表单功能测试');
        $this->assign('description', '测试DataTable的表单功能');
        
        return $this->fetch('Weline_DataTable::test/form-test.phtml');
    }

    /**
     * 字段类型测试
     */
    public function fieldTypes()
    {
        $this->assign('title', '字段类型测试');
        $this->assign('description', '测试DataTable支持的各种字段类型');
        
        return $this->fetch('Weline_DataTable::test/field-types.phtml');
    }

    /**
     * 过滤和搜索测试
     */
    public function filter()
    {
        $this->assign('title', '过滤和搜索测试');
        $this->assign('description', '测试DataTable的过滤和搜索功能');
        
        return $this->fetch('Weline_DataTable::test/filter-test.phtml');
    }

    /**
     * 分页功能测试
     */
    public function pagination()
    {
        $this->assign('title', '分页功能测试');
        $this->assign('description', '测试DataTable的分页功能');
        
        return $this->fetch('Weline_DataTable::test/pagination-test.phtml');
    }

    /**
     * 排序功能测试
     */
    public function sorting()
    {
        $this->assign('title', '排序功能测试');
        $this->assign('description', '测试DataTable的排序功能');
        
        return $this->fetch('Weline_DataTable::test/sorting-test.phtml');
    }

    /**
     * 导出功能测试
     */
    public function export()
    {
        $this->assign('title', '导出功能测试');
        $this->assign('description', '测试DataTable的导出功能');
        
        return $this->fetch('Weline_DataTable::test/export-test.phtml');
    }

    /**
     * 获取测试数据
     */
    public function getTestData()
    {
        try {
            $data = [
                'users' => [
                    ['id' => 1, 'name' => '张三', 'email' => 'zhangsan@test.com', 'status' => 1, 'created_at' => '2024-01-01'],
                    ['id' => 2, 'name' => '李四', 'email' => 'lisi@test.com', 'status' => 1, 'created_at' => '2024-01-02'],
                    ['id' => 3, 'name' => '王五', 'email' => 'wangwu@test.com', 'status' => 0, 'created_at' => '2024-01-03'],
                    ['id' => 4, 'name' => '赵六', 'email' => 'zhaoliu@test.com', 'status' => 1, 'created_at' => '2024-01-04'],
                    ['id' => 5, 'name' => '钱七', 'email' => 'qianqi@test.com', 'status' => 0, 'created_at' => '2024-01-05'],
                ],
                'products' => [
                    ['id' => 1, 'name' => '产品A', 'price' => 100.00, 'category' => '电子产品', 'stock' => 50],
                    ['id' => 2, 'name' => '产品B', 'price' => 200.00, 'category' => '服装', 'stock' => 30],
                    ['id' => 3, 'name' => '产品C', 'price' => 150.00, 'category' => '电子产品', 'stock' => 20],
                    ['id' => 4, 'name' => '产品D', 'price' => 80.00, 'category' => '食品', 'stock' => 100],
                    ['id' => 5, 'name' => '产品E', 'price' => 300.00, 'category' => '服装', 'stock' => 15],
                ]
            ];

            return $this->success('获取测试数据成功', $data);
        } catch (\Exception $e) {
            return $this->error('获取测试数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 简单功能测试
     */
    public function simple()
    {
        $this->assign('title', 'DataTable 简单功能测试');
        $this->assign('description', '测试DataTable的基本功能是否正常');
        
        return $this->fetch('Weline_DataTable::test/simple-test.phtml');
    }

    /**
     * 综合功能测试
     */
    public function comprehensive()
    {
        $this->assign('title', 'DataTable 综合功能测试');
        $this->assign('description', '全面测试DataTable组件的各项功能特性');
        
        return $this->fetch('Weline_DataTable::test/comprehensive-test.phtml');
    }
} 