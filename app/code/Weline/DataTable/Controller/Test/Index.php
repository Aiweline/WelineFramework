<?php
/**
 * DataTable 测试控制器
 */

namespace Weline\DataTable\Controller\Test;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{

    /**
     * 测试页面首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 表单功能测试
     */
    public function form()
    {
        return $this->fetch();
    }


}
