<?php
/**
 * DataTable 测试入口控制器
 * 
 * 此控制器作为DataTable模块测试功能的入口点
 * 重定向到综合测试控制器
 */

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_DataTable::test_index', 'DataTable测试入口', 'mdi mdi-test-tube', 'DataTable模块测试功能入口', 'Weline_DataTable::datatable')]
class Index extends BackendController
{
    /**
     * 测试入口 - 重定向到综合测试页面
     */
    #[Acl('Weline_DataTable::test_index_index', '测试首页', 'mdi mdi-home', 'DataTable测试首页')]
    public function index()
    {
        // 重定向到综合测试控制器的index方法
        $this->redirect($this->_url->getUrl('datatable/backend/test/comprehensive/index'));
    }
}

