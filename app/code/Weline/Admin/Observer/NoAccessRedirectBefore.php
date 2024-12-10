<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/2 17:13:52
 */

namespace Weline\Admin\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

class NoAccessRedirectBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Framework\Http\Request
     */
    private Request $request;

    function __construct(
        Request $request
    )
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        if ($this->request->isBackend()) {
            $this->request->_response->redirect($this->request->getUrlBuilder()->getBackendUrl('admin/login'));
        }
    }
}