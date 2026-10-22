<?php

declare(strict_types=1);

namespace Weline\Frontend\Observer;

use Weline\Framework\Deploy\StaticAssetHtmlRewriter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Response;

/**
 * 最终 HTML 统一加静态构建代次：/static/... → /static/{buildId}/...
 * 这样模板里历史硬编码的 CSS/JS/图片也和老 FPC 页面之外的资源同代。
 */
final class StaticAssetResponseRewriter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $response = $event->getData('response');
        if (!$response instanceof Response || $response->getStatusCode() !== 200) {
            return;
        }
        $contentType = $response->getHeader('Content-Type');
        $contentType = \strtolower(\is_array($contentType) ? (string)\reset($contentType) : (string)$contentType);
        if ($contentType !== '' && !\str_contains($contentType, 'text/html')) {
            return;
        }
        $body = $response->getBody();
        if ($body === '' || (!\str_contains($body, '/static/') && !\str_contains($body, StaticAssetHtmlRewriter::PAIR_PLACEHOLDER) && !\str_contains($body, StaticAssetHtmlRewriter::DEPLOY_PLACEHOLDER))) {
            return;
        }
        $rewritten = StaticAssetHtmlRewriter::rewrite($body);
        if ($rewritten !== $body) {
            $response->setBody($rewritten);
        }
    }
}
