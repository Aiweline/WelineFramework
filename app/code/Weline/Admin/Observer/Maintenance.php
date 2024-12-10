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
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;

class Maintenance implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var Request $req */
        $req   = ObjectManager::getInstance(Request::class);
        $block = Block::getInstance();
        /**@var DataObject $data */
        $data         = $event->getData('data');
        $white_urls   = $data->getData('white_urls');
        $white_urls[] = 'assets/images/favicon.ico';
        $white_urls[] = 'assets/css/bootstrap.min.css';
        $white_urls[] = 'assets/css/icons.min.css';
        $white_urls[] = 'assets/css/app.min.css';
        $white_urls[] = 'assets/images/logo-dark.png';
        $white_urls[] = 'assets/images/logo-light.png';

        $white_urls[] = 'assets/libs/jquery/jquery.min.js';
        $white_urls[] = 'assets/libs/bootstrap/js/bootstrap.bundle.min.js';
        $white_urls[] = 'assets/libs/metismenu/metisMenu.min.js';
        $white_urls[] = 'assets/libs/simplebar/simplebar.min.js';
        $white_urls[] = 'assets/libs/node-waves/waves.min.js';
        $white        = false;
        foreach ($white_urls as $white_url_string) {
            if (str_contains($req->getUri(), $white_url_string)) {
                $white = true;
                break;
            }
        }
        $data->setData('white_urls', $white_urls);
        if (!$white) {
            die($block->fetchHtml('Weline_Admin::templates/maintenance.phtml'));
        }
    }
}
