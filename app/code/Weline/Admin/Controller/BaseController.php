<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\Admin\Observer\BackendWhitelistUrl;
class BaseController extends BackendController
{
    public function __init()
    {
        parent::__init();
        $this->assign('title', __('欢迎使用WelineFramework框架后台系统！'));
        $this->assign('logo_title', __('WelineFramework'));
    }
    
    protected function fetchBase(string $fileName = '', array $data = []): mixed
    {
        if ($data) {
            $this->assign($data);
        }
        # 如果指定了模板就直接读取
        if ($fileName) {
            if (is_int(strpos($fileName, '::'))) {
                return $this->getTemplate()->fetch($fileName);
            }
            //            return $this->getTemplate()->fetch('templates' . DS .$fileName);
        }
        $controller_class_name = $this->request->getRouterData('class/controller_name');
        if ($fileName === '') {
            if (in_array(strtoupper($this->request->getRouterData('class/method')), $this->request::METHODS)) {
                $fileName = $controller_class_name;
            } else {
                $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method');
            }
        } elseif (is_bool(strpos($fileName, '/')) || is_bool(strpos($fileName, '\\'))) {
            $fileName = $controller_class_name . DS . $fileName;
        } else {
            $fileName = $controller_class_name . '/' . $this->request->getRouterData('class/method') . DS . $fileName;
        }
        // 渲染Weline\Admin\view\templates\Backend\page-layout\main-content-before.phtml
        $before = $this->getTemplate()->fetch( 'Weline_Admin::templates/Backend/page-layout/main-content-before.phtml');
        // 渲染正文文件
        $content = $this->getTemplate()->fetch('templates' . DS . $fileName);
        // 渲染Weline\Admin\view\templates\Backend\page-layout\main-content-after.phtml
        $after = $this->getTemplate()->fetch('Weline_Admin::templates/Backend/page-layout/main-content-after.phtml');
        return $before . $content . $after;
    }
}
