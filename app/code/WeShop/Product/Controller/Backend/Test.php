<?php

namespace WeShop\Product\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('WeShop_Product::product_test', 'Product test actions', 'mdi mdi-test-tube', 'Access product backend test route', 'WeShop_Product::product')]
class Test extends BackendController
{
    private \WeShop\Product\Model\Test $test;

    function __construct(\WeShop\Product\Model\Test $test)
    {
        $this->test = $test;
    }

    #[Acl('WeShop_Product::product_test_index', 'Open product test route', 'mdi mdi-test-tube', 'Open product backend test route')]
    function index()
    {
//        $this->test->setName('test')
//        ->save();
//        $tests = $this->test->select()->fetchArray();
//        $tests = $this->test->where('name', 'test')->find()->getLastSql();
        $a = '前端页面';
        $this->assign('a', $a);
        return $this->fetch();
    }
}
