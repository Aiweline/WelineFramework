<?php

declare(strict_types=1);

namespace Weline\Component\Controller\Backend;

use Weline\Admin\Controller\BaseController;

/**
 * 后台 OffCanvas 结果页（成功/失败），供 iframe 重定向使用。
 * 路由：component/offcanvas/success -> getSuccess，component/offcanvas/error -> getError（由 redirect 生成后端 URL）。
 */
class Offcanvas extends BaseController
{
    public function getSuccess(): string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? __('请求成功！'));
        $this->assign('reload', $this->request->getParam('reload') ?? 1);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? '');
        $this->assign('url', $this->request->getParam('url') ?? '');
        return (string) $this->getTemplate()->fetchHtml('Weline_Component::templates/Offcanvas/success');
    }

    public function getError(): string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? __('请求失败！'));
        $this->assign('reload', $this->request->getParam('reload') ?? 0);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? '');
        $this->assign('url', $this->request->getParam('url') ?? '');
        return (string) $this->getTemplate()->fetchHtml('Weline_Component::templates/Offcanvas/error');
    }
}
