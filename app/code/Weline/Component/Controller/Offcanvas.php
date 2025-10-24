<?php

namespace Weline\Component\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Offcanvas extends FrontendController
{
    public function success(string $msg = '处理成功！', mixed $data = '程序已正常处理所有流程！', int $code = 200): string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? $msg);
        $this->assign('reload', $this->request->getParam('reload') ?? 1);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? $data);
        return $this->fetch();
    }

    public function error(string $msg = '处理失败！', mixed $data = '程序出现了问题，未能成功执行所有流程！', int $code = 400): string
    {
        $this->assign('msg', $this->request->getParam('msg') ?? $msg);
        $this->assign('reload', $this->request->getParam('reload') ?? 0);
        $this->assign('time', $this->request->getParam('time') ?? 3);
        $this->assign('content', $this->request->getParam('content') ?? $data);
        return $this->fetch();
    }
}