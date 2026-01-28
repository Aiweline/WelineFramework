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
        
        // 获取当前网站ID（从 URL 解析结果或当前请求）
        $websiteId = 0;
        if (isset($parse['website']['website_id'])) {
            $websiteId = (int)$parse['website']['website_id'];
        } elseif (isset($parse['server']['WELINE_WEBSITE_ID']) && $parse['server']['WELINE_WEBSITE_ID'] !== '') {
            $websiteId = (int)$parse['server']['WELINE_WEBSITE_ID'];
        } else {
            $websiteId = UrlRewrite::getCurrentWebsiteId();
        }
        
        $match_uri = strtolower($uri);
        // 按 website_id 查询重写规则（不回退到 website_id=0）
        // 先尝试 match_uri，再尝试 real_uri
        $rewrite = $this->urlRewrite->reset()
            ->clearQuery()
            ->where(UrlRewrite::fields_WEBSITE_ID, $websiteId)
            ->where(UrlRewrite::fields_PATH, $match_uri)
            ->find()->fetch();
        
        if (!$rewrite->getId() && $match_uri !== $real_uri) {
            $rewrite = $this->urlRewrite->reset()
                ->clearQuery()
                ->where(UrlRewrite::fields_WEBSITE_ID, $websiteId)
                ->where(UrlRewrite::fields_PATH, $real_uri)
                ->find()->fetch();
        }
        if ($rewrite->getId()) {
            $rewrite_path = $rewrite->getData('rewrite');
            // FIXME 可能出现替换失败，导致无法访问
            $url = str_ireplace($real_uri, $rewrite_path, $url);
        }
        $event->setData('data', $url);
    }
}