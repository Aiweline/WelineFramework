<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/23 19:52:23
 */

namespace Weline\UrlManager\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\UrlManager\Model\UrlRewrite;

class RouterRewrite implements \Weline\Framework\Event\ObserverInterface
{
    private UrlRewrite $urlRewrite;

    public function __construct(
        UrlRewrite $urlRewrite
    )
    {
        $this->urlRewrite = $urlRewrite;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        $uri = ltrim($event->getData(), '/');
        $rewrite = $this->urlRewrite->load(UrlRewrite::fields_REWRITE, $uri);
        if ($rewrite->getId()) {
            # 读取原地址
            $query = Url::parse_url($uri, 'query');
            $origin_path = '/' . $rewrite->getData('path');
            if ($query) {
                if (str_contains($origin_path, '?')) {
                    $origin_path .= '&' . $query;
                } else {
                    $origin_path .= '?' . $query;
                }
            }
            $event->setData('data', $origin_path);
            $query = Url::parse_url($origin_path, 'query');
            parse_str($query, $_GET);
        } else {
            # 找不到尝试使用path匹配
            $path = Url::parse_url($uri, 'path');
            $rewrite = $this->urlRewrite->reset()->load(UrlRewrite::fields_REWRITE, $path);
            if ($rewrite->getId()) {
                # 读取原地址
                $query = Url::parse_url($uri, 'query');
                $origin_path = '/' . $rewrite->getData('path');
                if ($query) {
                    if (str_contains($origin_path, '?')) {
                        $origin_path .= '&' . $query;
                    } else {
                        $origin_path .= '?' . $query;
                    }
                }
                $event->setData('data', $origin_path);
                $query = Url::parse_url($origin_path, 'query');
                parse_str($query, $_GET);
            }
        }
    }
}
