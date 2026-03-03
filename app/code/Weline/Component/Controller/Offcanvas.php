<?php

namespace Weline\Component\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Offcanvas extends FrontendController
{
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): array|string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? $msg);
        $this->assign('reload', $this->request->getParam('reload') ?? 1);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? $data);
        return $this->fetch();
    }

    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 404, ?string $title = null): array|string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? $msg);
        $this->assign('reload', $this->request->getParam('reload') ?? 0);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? $data);
        return $this->fetch();
    }
}