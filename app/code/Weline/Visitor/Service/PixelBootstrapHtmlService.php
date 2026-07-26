<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 生成前台访客像素引导 HTML（配置 + pixel.js 懒加载）。
 *
 * 与主题 hook Weline_Theme::frontend::layouts::base::body-end 行为一致，
 * 供绕过主题 layout 自建整页输出的渲染链路（如 PageBuilder ai_html 页面）注入使用。
 *
 * 开发面板 Tab 不在此服务职责内，见 VisitorPanelBootstrapHtmlService /
 * VisitorPanelBootstrapObserver 的框架全局注入。
 */
class PixelBootstrapHtmlService
{
    private const PIXEL_SCRIPT_VERSION = '20260724-section-code-2';

    public function __construct(
        private readonly VisitorTrackingConfig $trackingConfig
    ) {
    }

    /**
     * 返回可直接拼接到 </body> 前的引导 HTML；像素与 GA4 均被禁用时返回空串。
     */
    public function render(): string
    {
        $config = $this->trackingConfig->getRuntimeConfig();

        $pixelEnabled = !empty($config['pixel']['enabled']);
        $ga4Enabled = !empty($config['ga4']['enabled']);

        // 与主题 hook 保持一致：允许通过事件关闭像素输出
        $pixelName = 'weshop_storefront_behavior';
        $eventData = new DataObject([
            'pixel_code' => '',
            'name' => $pixelName,
            'enable' => $pixelEnabled ? 1 : 0,
        ]);
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch('Weline_Visitor::taglib_pixel', $eventData);
        } catch (\Throwable) {
            // 事件系统不可用时按配置值继续
        }
        $pixelEnabled = $pixelEnabled && !empty($eventData->getData('enable'));
        $config['pixel']['enabled'] = $pixelEnabled;

        if (!$pixelEnabled && !$ga4Enabled) {
            return '';
        }

        return $this->renderPixelBootstrap($config, $pixelName);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderPixelBootstrap(array $config, string $pixelName): string
    {
        $configJson = \json_encode(
            $config,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP
        );
        if ($configJson === false) {
            $configJson = '{}';
        }

        // Site cookies are HttpOnly — inject readable env for GA4 funnel attribution.
        $pixelEnvJson = \json_encode(
            $this->buildPixelEnvironmentPayload(),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP
        );
        if ($pixelEnvJson === false) {
            $pixelEnvJson = '{}';
        }

        $scriptUrl = $this->moduleStaticUrl('Weline/Visitor', 'js/pixel.js') . '?v=' . self::PIXEL_SCRIPT_VERSION;
        // pixel.js 通过 Weline.Api.resource('visitor') 走 worker 通道上报；
        // 自建整页的渲染链路没有 theme.js，需要一并加载 API 运行时。
        $apiScriptUrl = $this->moduleStaticUrl('Weline/Frontend', 'js/weline-api.js') . '?v=' . self::PIXEL_SCRIPT_VERSION;
        // 自建页面无 theme.js 提供的 DEV 标记，weline-api.js 会按 PROD 规则解析 worker URL，
        // 这里由服务端直接给出正确地址，避免 404。
        $workerScriptUrl = $this->moduleStaticUrl('Weline/Frontend', 'js/weline-api-worker.js');
        $safePixelName = \htmlspecialchars($pixelName, \ENT_QUOTES, 'UTF-8');

        return <<<HTML
<script>
(function () {
    'use strict';

    var visitorTrackingConfig = {$configJson};
    window.__WelineVisitorTrackingConfig = visitorTrackingConfig;
    window.__WelinePixelEnv = Object.assign({}, window.__WelinePixelEnv || {}, {$pixelEnvJson});
    window.__SITE_GA4__ = Object.assign({}, window.__SITE_GA4__ || {}, visitorTrackingConfig.ga4 || {}, {
        source: (visitorTrackingConfig.ga4 && visitorTrackingConfig.ga4.source) || 'Weline_Visitor SystemConfig'
    });
    try {
        window.dispatchEvent(new CustomEvent('weline:visitor-tracking-config', { detail: visitorTrackingConfig }));
    } catch (error) {
    }

    if (window.__WelinePixelLazyScheduled) {
        return;
    }
    window.__WelinePixelLazyScheduled = true;

    function isLocalDevHost() {
        try {
            var host = String(window.location && window.location.hostname || '');
            return !!(window.DEV
                || window.WELINE_ENV === 'DEV'
                || window.__WELINE_DEBUG__
                || host === 'localhost'
                || host === '127.0.0.1'
                || /\\.weline\\.test$/i.test(host));
        } catch (e) {
            return !!(window.DEV || window.WELINE_ENV === 'DEV');
        }
    }

    function devLog() {
        if (!isLocalDevHost() || typeof console === 'undefined' || typeof console.log !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[WelinePixel]');
        console.log.apply(console, args);
    }

    function loadWelinePixel(reason) {
        if (window.__WelinePixelLazyLoaded || window.__WelinePixelLoaded) {
            return;
        }

        window.__WelinePixelLazyLoaded = true;
        window.__WelinePixelName = '{$safePixelName}';
        if (!(window.Weline && window.Weline.Api && window.Weline.Api.__full)) {
            window.WelineApiConfig = Object.assign({}, window.WelineApiConfig || {}, {
                workerUrl: '{$workerScriptUrl}'
            });
            var apiScript = document.createElement('script');
            apiScript.src = '{$apiScriptUrl}';
            apiScript.async = true;
            document.head.appendChild(apiScript);
        }
        var script = document.createElement('script');
        script.src = '{$scriptUrl}';
        script.async = true;
        script.onload = function () {
            devLog('loaded', { reason: reason || 'schedule', hasTrack: !!(window.WelinePixel && window.WelinePixel.track) });
        };
        script.onerror = function () {
            devLog('load-failed', { reason: reason || 'schedule', src: script.src });
        };
        document.head.appendChild(script);
        devLog('loading', { reason: reason || 'schedule', src: script.src });
    }

    function scheduleWelinePixel() {
        // Local/dev: load immediately so CTA clicks are measurable while debugging.
        // Production keeps the delayed idle schedule.
        if (isLocalDevHost()) {
            loadWelinePixel('eager-dev');
            return;
        }
        window.setTimeout(function () {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(function () { loadWelinePixel('idle'); }, { timeout: 8000 });
                return;
            }
            window.setTimeout(function () { loadWelinePixel('timeout'); }, 1000);
        }, 3500);
    }

    function closestCta(node) {
        if (!node || !node.closest) {
            return null;
        }
        return node.closest([
            '[data-cta]',
            '[data-cta-event]',
            '[data-pixel-event]',
            '[data-pb-ai-action]',
            '[class*="weline-pixel::"]',
            'a[class*="-cta"]',
            'button[class*="-cta"]',
            '.pb-c-cta'
        ].join(','));
    }

    // Capture CTA clicks even before pixel.js finishes loading.
    document.addEventListener('click', function (event) {
        var cta = closestCta(event.target);
        if (!cta) {
            return;
        }
        var className = typeof cta.className === 'string' ? cta.className : '';
        var pixelClass = '';
        className.split(/\\s+/).forEach(function (token) {
            if (token.indexOf('weline-pixel::') === 0 && token.indexOf(':value') === -1) {
                pixelClass = token.replace('weline-pixel::', '');
            }
        });
        var info = {
            tag: cta.tagName,
            text: ((cta.innerText || cta.textContent || '') + '').trim().slice(0, 80),
            href: cta.getAttribute('href') || cta.href || '',
            action: cta.getAttribute('data-pb-ai-action') || '',
            dataCtaEvent: cta.getAttribute('data-cta-event') || '',
            pixelClass: pixelClass,
            className: className,
            pixelReady: !!(window.WelinePixel && typeof window.WelinePixel.track === 'function'),
            env: {
                page_location: window.location.href,
                page_path: window.location.pathname || '/',
                page_title: document.title || '',
                page_referrer: document.referrer || '',
                page_hostname: window.location.hostname || '',
                website_id: (window.__WelinePixelEnv && window.__WelinePixelEnv.website_id) || '',
                website_code: (window.__WelinePixelEnv && window.__WelinePixelEnv.website_code) || '',
                website_url: (window.__WelinePixelEnv && window.__WelinePixelEnv.website_url) || '',
                language: (window.__WelinePixelEnv && window.__WelinePixelEnv.language) || '',
                currency: (window.__WelinePixelEnv && window.__WelinePixelEnv.currency) || ''
            }
        };
        if (isLocalDevHost()) {
            console.log('[WelineCTA] click', info);
        }
        if (!window.WelinePixel || typeof window.WelinePixel.track !== 'function') {
            loadWelinePixel('cta-click');
        }
    }, true);

    window.__WelineLoadPixel = loadWelinePixel;
    if (document.readyState === 'complete') {
        scheduleWelinePixel();
    } else {
        window.addEventListener('load', scheduleWelinePixel, { once: true });
    }
})();
</script>
HTML;
    }

    /**
     * Server-injected pixel environment (cookies are HttpOnly and invisible to JS).
     *
     * @return array<string, string>
     */
    private function buildPixelEnvironmentPayload(): array
    {
        $websiteUrl = (string)WelineEnv::server('WELINE_WEBSITE_URL', '');
        if ($websiteUrl === '') {
            $websiteUrl = (string)($_SERVER['WELINE_WEBSITE_URL'] ?? '');
        }
        try {
            if ($websiteUrl !== '' && \str_contains($websiteUrl, '%')) {
                $decoded = \rawurldecode($websiteUrl);
                if (\is_string($decoded) && $decoded !== '') {
                    $websiteUrl = $decoded;
                }
            }
        } catch (\Throwable) {
        }

        return [
            'website_id' => (string)WelineEnv::server('WELINE_WEBSITE_ID', (string)($_SERVER['WELINE_WEBSITE_ID'] ?? '')),
            'website_code' => (string)WelineEnv::server('WELINE_WEBSITE_CODE', (string)($_SERVER['WELINE_WEBSITE_CODE'] ?? '')),
            'website_url' => $websiteUrl,
            'language' => (string)WelineEnv::server('WELINE_USER_LANG', (string)($_SERVER['WELINE_USER_LANG'] ?? '')),
            'currency' => (string)WelineEnv::server('WELINE_USER_CURRENCY', (string)($_SERVER['WELINE_USER_CURRENCY'] ?? '')),
        ];
    }

    /**
     * 模块静态资源的可访问 URL；与 TraitTemplate::fetchTagSource 的兜底规则一致。
     */
    private function moduleStaticUrl(string $modulePath, string $file): string
    {
        $url = '/' . \trim($modulePath, '/') . '/view/statics/' . \ltrim($file, '/');
        if (\defined('PROD') && PROD) {
            $themePath = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $url = '/static/' . \str_replace('\\', '/', (string)$themePath) . $url;
        }

        return $url;
    }
}
