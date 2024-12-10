<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;

class BackendNoLoginRedirectUrl implements \Weline\Framework\Event\ObserverInterface
{
    private Url $url;

    public function __construct(
        Url $url
    )
    {
        $this->url = $url;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var DataObject $data */
        $data = $event->getData('data');
        $data->setData('no_login_redirect_url', $this->url->getBackendUrl('admin/login'));
    }
}
