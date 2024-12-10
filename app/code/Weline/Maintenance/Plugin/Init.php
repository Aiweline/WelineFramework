<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/11
 * 时间：0:02
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Maintenance\Plugin;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Init
{
    public function beforeStart(
        \Weline\Framework\Router\Core $router
    )
    {
        if (Env::getInstance()->getConfig('maintenance', false)) {
            /**@var Request $req */
            $req = ObjectManager::getInstance(Request::class);
            /**@var EventsManager $event */
            $event = ObjectManager::getInstance(EventsManager::class);
            $data  = new DataObject(['white_urls' => []]);
            $event->dispatch('Weline_Framework::maintenance', ['data' => $data]);
            $white_urls = $data->getData('white_urls');
            $white      = false;
            foreach ($white_urls as $white_url_string) {
                if (str_contains($req->getUri(), $white_url_string)) {
                    $white = true;
                    break;
                }
            }
            if (!$white) {
                throw new Exception(__('程序维护中...'));
            }
        }
    }
}
