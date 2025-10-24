<?php

namespace WeShop\Product\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Test extends BackendController
{
    private \WeShop\Product\Model\Test $test;

    function __construct(\WeShop\Product\Model\Test $test)
    {
        $this->test = $test;
    }

    function index()
    {
//        $this->test->setName('test')
//        ->save();
//        $tests = $this->test->select()->fetchArray();
//        dd($tests);
//        $tests = $this->test->where('name', 'test')->find()->getLastSql();
//        p($tests);
        $a = '前端页面';
        $this->assign('a', $a);
        return $this->fetch();
    }
}