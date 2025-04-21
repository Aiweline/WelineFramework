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
    public function execute(Event &$event)
    {
        $url = $event->getData('data');
        if (!str_contains($url, 'forum')) {
            return;
        }
        Debug::env('re');
        $parse = \Weline\Framework\Http\Url::parser($url);
        if (is_string($parse)) {
            return;
        }
        $uri = $parse['uri'] ?? '';
        if (empty($uri)) {
            return;
        }
        $uri = ltrim($uri, '/');
        $rewrite = $this->urlRewrite->reset()
            ->where('path', strtolower($uri))
            ->find()
            ->fetch();
        if ($rewrite->getId()) {
            $url = $rewrite->getData('rewrite');
        }
        $event->setData('data', $url);
    }
}