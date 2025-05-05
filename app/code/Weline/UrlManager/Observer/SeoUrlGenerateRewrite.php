<?php

namespace Weline\UrlManager\Observer;

use Weline\Framework\App\Debug;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\UrlManager\Controller\Backend\Url;
use Weline\UrlManager\Model\UrlRewrite;

class SeoUrlGenerateRewrite implements ObserverInterface
{

    public function __construct(private UrlRewrite $urlRewrite)
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $url = $event->getData('data');
        $parse = \Weline\Framework\Http\Url::parser($url);
        if (is_string($parse)) {
            return;
        }
        $uri = $parse['uri'] ?? '';
        if (empty($uri)) {
            return;
        }
        $uri = ltrim($uri, '/');
        $real_uri = $uri;
        $currency = $parse['currency'] ?? '';
        $real_uri = substr($real_uri, strlen($currency . '/'));
        $language = $parse['language'] ?? '';
        $real_uri = substr($real_uri, strlen($language . '/'));
        if (empty($real_uri)) {
            return;
        }
        $match_uri = strtolower($uri);
        $rewrite = $this->urlRewrite->reset()
            ->where('path', $match_uri, '=', 'or')
            ->where('path', $real_uri)
            ->find()->fetch();
        if ($rewrite->getId()) {
            $rewrite_path = $rewrite->getData('rewrite');
            // FIXME 可能出现替换失败，导致无法访问
            $url = str_ireplace($real_uri, $rewrite_path, $url);
        }
        $event->setData('data', $url);
    }
}