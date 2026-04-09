<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\View\Block;
use Weline\Maintenance\Helper\UrlParser;

class Maintenance implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 在 run_before 阶段，使用轻量级 URL 解析器判断请求类型（不触发事件，不查询数据库）
        $parse = $event->getData('parse');
        $uri = $parse['uri'];
        $isApiRequest = UrlParser::isApiRequest($_SERVER['ORIGIN_REQUEST_URI'] ?? '');
        $isBackend = UrlParser::isBackendRequest($_SERVER['ORIGIN_REQUEST_URI'] ?? '');
        
        // 如果 area 不是 API，再检查 Accept 头（兼容某些特殊情况）
        if (!$isApiRequest) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isApiRequest = str_contains($acceptHeader, 'application/json');
        }
        // 仅处理前端非 API 请求
        if ($isApiRequest || $isBackend) {
            return;
        }
        // 添加当前模块名到 Request 对象
        $request = w_obj(Request::class);
        /**@var Request $request */
        $request->addModule('Weline_Frontend');

        $block = Block::getInstance();
        /**@var DataObject $data */
        $data = $event->getData('data');
        $white_urls = $data->getData('white_urls') ?? [];
        $white_urls[] = 'img/favicon.png';
        $white_urls[] = 'assets/css/bootstrap.min.css';
        $white_urls[] = 'assets/css/icons.min.css';
        $white_urls[] = 'assets/css/app.min.css';

        $white_urls[] = 'assets/fonts/remixicon.ttf';
        $white_urls[] = 'assets/fonts/remixicon.woff';
        $white_urls[] = 'assets/fonts/remixicon.woff2';

        $white_urls[] = 'assets/images/logo-dark.png';
        $white_urls[] = 'assets/images/logo-light.png';

        $white_urls[] = 'assets/libs/jquery/jquery.min.js';
        $white_urls[] = 'assets/libs/bootstrap/js/bootstrap.bundle.min.js';
        $white_urls[] = 'assets/libs/metismenu/metisMenu.min.js';
        $white_urls[] = 'assets/libs/simplebar/simplebar.min.js';
        $white_urls[] = 'assets/libs/node-waves/waves.min.js';
        $white = false;
        foreach ($white_urls as $white_url_string) {
            if (str_contains($uri, $white_url_string)) {
                $white = true;
                break;
            }
        }

        $data->setData('white_urls', $white_urls);
        if (!$white) {
            // 获取语言（从事件数据中读取，如果事件数据中有的话）
            $lang = $data->getData('language') ?? \w_env('user.lang') ?? \w_env_cookie('WELINE_USER_LANG') ?? 'zh_Hans_CN';
            // 设置语言到 Request，以便模板能够使用正确的语言
            $request->setData('WELINE_USER_LANG', $lang);
            \w_env_set('user.lang', $lang);
            
            // 标记为已处理，阻止 MaintenanceInterceptor 继续执行
            $data->setData('handled', true);
            // 使用 ResponseTerminateException 替代 die()，由 Runtime 层统一处理
            throw new \Weline\Framework\Http\ResponseTerminateException(
                503,
                $block->fetchHtml('Weline_Frontend::templates/maintenance.phtml'),
                ['Content-Type' => 'text/html; charset=UTF-8', 'Retry-After' => '3600']
            );
        }
    }
}
