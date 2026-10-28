<?php

declare(strict_types=1);

namespace Weline\App\Controller\Frontend;

use Weline\App\Service\Pwa\PwaManifestBuilder;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;

/**
 * 店面 PWA 三件套（manifest / service worker / 图标）。
 *
 * 路由：/manifest.webmanifest、/sw.js、/pwa-icon-192.png、/pwa-icon-512.png
 * （由 App\Controller\Router 拥有，App\Observer\ProcessAppUriBefore 只做事件适配）
 *
 * manifest 数据面由 PwaManifestBuilder 从 Website 品牌 + 当前 lang/currency 生成；
 * head 标签由 App 的 Theme hook 注入，随页面进入 FPC，不再写死站点主题文件。
 */
class Pwa extends FrontendController
{
    public function manifest(): Response
    {
        $websiteId = $this->resolveWebsiteId();
        $lang = $this->resolveLang();
        $currency = $this->resolveCurrency();
        $builder = ObjectManager::getInstance(PwaManifestBuilder::class);
        $payload = $builder->build($websiteId, $lang, $currency, $this->origin());

        $response = Response::text(
            (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            'application/manifest+json; charset=utf-8'
        );
        $response->setHeader('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
        $response->setHeader('Vary', 'Accept-Language');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->request->getResponse()->setHeader('Content-Type', 'application/manifest+json; charset=utf-8');
        if ($lang !== '') {
            $response->setHeader('Content-Language', \str_replace('_', '-', $lang));
        }

        return $response;
    }

    public function sw(): Response
    {
        $js = <<<'JS'
const CACHE = 'weline-shell-v3';
const PRECACHE = ['/manifest.webmanifest', '/pwa-icon-192.png'];
self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE).catch(() => {})));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const isAsset = /\.(?:css|js|mjs|png|jpe?g|webp|avif|svg|ico|woff2?|ttf|otf)(?:$|\?)/i.test(url.pathname);
  if (isAsset) {
    // 静态资源：网络优先（带条件校验），断网回缓存。
    // 站点静态资源 URL 不带内容指纹（_weline_dev 不随文件改动变化），
    // 旧策略「缓存优先」会把改过的 CSS/JS 永久钉在旧字节上；
    // cache:'no-cache' 让每次请求都与服务端 ETag/Last-Modified 校验，
    // 未改动时是 304，改动后立即拿到新内容。
    e.respondWith(
      fetch(req, { cache: 'no-cache' }).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }).catch(() => caches.match(req).then((hit) => hit || Response.error()))
    );
    return;
  }
  // 页面：断网回缓存（不缓存后台/API）。
  // 仅离线兜底：在线时绝不 respondWith 重新 fetch 导航请求。
  // SW 里 fetch(req) 发出的请求 Sec-Fetch-Dest 会塌缩为 empty，
  // 后台页面凭证（backend attestation）据此拒绝铸造，主题编辑器等
  // auth=backend 接口会全部 403（「不满足操作授权要求」）。在线导航
  // 直接放行给浏览器原生处理，凭证正常铸造；断网时才回缓存。
  const isNavigation = req.mode === 'navigate'
    || (req.headers.get('accept') || '').includes('text/html');
  if (isNavigation && navigator.onLine === false) {
    e.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      }).catch(() => caches.match(req).then((hit) => hit || caches.match('/')))
    );
  }
});
JS;
        $response = Response::text($js, 200, 'application/javascript; charset=utf-8');
        $response->setHeader('Service-Worker-Allowed', '/');
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->request->getResponse()->setHeader('Content-Type', 'application/javascript; charset=utf-8');

        return $response;
    }

    public function icon192(): Response
    {
        return $this->icon('icon-192.png');
    }

    public function icon512(): Response
    {
        return $this->icon('icon-512.png');
    }

    private function icon(string $file): Response
    {
        $path = BP . 'app/code/Weline/App/view/statics/pwa/' . $file;
        if (!is_file($path)) {
            return Response::text('not found', 404, 'text/plain; charset=utf-8');
        }
        $response = Response::text((string)file_get_contents($path), 200, 'image/png');
        $response->setHeader('Cache-Control', 'public, max-age=604800');
        $this->request->getResponse()->setHeader('Content-Type', 'image/png');

        return $response;
    }

    private function origin(): string
    {
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = (string)(
            $this->request->getServer('HTTP_HOST')
            ?: $this->request->getServer('SERVER_NAME')
            ?: 'localhost'
        );

        return $scheme . '://' . $host;
    }

    private function resolveWebsiteId(): int
    {
        try {
            $websiteId = WelineEnv::getWebsiteId();
            if ($websiteId !== null) {
                return $websiteId;
            }
        } catch (\Throwable) {
        }
        try {
            return (int)(WebsiteData::getWebsiteId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveLang(): string
    {
        $candidate = \trim((string)($this->request->getParam('lang', $this->request->getParam('locale', '')) ?? ''));
        if ($candidate !== '') {
            try {
                $normalized = State::normalizeLanguageCode($candidate);
                if ($normalized !== '' && State::isAllowedLanguageCode($normalized)) {
                    return $normalized;
                }
            } catch (\Throwable) {
            }
        }

        try {
            $lang = \trim((string)WelineEnv::getLang());
            if ($lang !== '') {
                return $lang;
            }
        } catch (\Throwable) {
        }
        try {
            return State::resolveAreaDefaultLanguage();
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveCurrency(): string
    {
        $candidate = \strtoupper(\trim((string)($this->request->getParam('currency', '') ?? '')));
        if ($candidate !== '') {
            try {
                if (State::isAllowedCurrencyCode($candidate)) {
                    return $candidate;
                }
            } catch (\Throwable) {
            }
        }

        try {
            $currency = \strtoupper(\trim((string)WelineEnv::getCurrency()));
            if ($currency !== '') {
                return $currency;
            }
        } catch (\Throwable) {
        }
        try {
            return State::resolveWebsiteDefaultCurrency();
        } catch (\Throwable) {
            return '';
        }
    }
}
