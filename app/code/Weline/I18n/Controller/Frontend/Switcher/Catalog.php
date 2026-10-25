<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Frontend\Switcher;

use Weline\Framework\App\Controller\FrontendController;
use Weline\I18n\Taglib\LanguageSwitcher;

/**
 * 公开语言目录（只读、HTTP 可缓存）。
 *
 * 语言/货币目录对所有访客一致，不需要 Worker Session。
 * 走 QueryBin 会给每个访客分配会话，量大时触发
 * 「工作会话已达上限」；此端点直接输出缓存友好的 JSON，
 * 前端语言面板优先使用它，QueryBin 仅作兜底。
 *
 * @Extra type=fpc enabled=true ttl=300
 */
class Catalog extends FrontendController
{
    public function get()
    {
        $payload = LanguageSwitcher::renderLazyPanelCatalog([
            'website_id' => (int)($this->request->getParam('website_id') ?? 0),
            'display_locale_code' => (string)($this->request->getParam('display_locale_code') ?? ''),
            'current' => (string)($this->request->getParam('current') ?? ''),
        ]);

        $response = $this->fetchJson($this->success('', $payload));
        // 目录对所有访客一致：允许浏览器/CDN 缓存，避免每次开面板都回源。
        if (\is_object($response) && \method_exists($response, 'setHeader')) {
            $response->setHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
            $response->setHeader('Vary', 'Accept-Encoding');
        }

        return $response;
    }
}
