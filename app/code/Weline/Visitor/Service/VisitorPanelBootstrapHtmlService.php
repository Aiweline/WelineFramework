<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\DeveloperAccessPolicy;

/**
 * 生成开发面板「访问事件」Tab 的全局引导 HTML。
 *
 * 与 PixelBootstrapHtmlService 解耦：面板 Tab 由框架 HTML 响应全局注入，
 * 不依赖主题 layout / AI 自建整页是否走 body-end hook。
 */
class VisitorPanelBootstrapHtmlService
{
    private const PANEL_SCRIPT_VERSION = '20260817-lazy-panel-1';

    public function shouldInject(): bool
    {
        try {
            return (bool)ObjectManager::getInstance(DeveloperAccessPolicy::class)->shouldInjectBootstrap();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 返回可拼接到 </body> 前的脚本标签；策略拒绝时返回空串。
     */
    public function render(): string
    {
        if (!$this->shouldInject()) {
            return '';
        }

        $panelScriptUrl = $this->moduleStaticUrl('Weline/Visitor', 'js/weline-panel-visitor.js')
            . '?v=' . self::PANEL_SCRIPT_VERSION;
        $panelScriptUrlJson = \json_encode(
            $panelScriptUrl,
            \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP
        );
        if (!\is_string($panelScriptUrlJson)) {
            return '';
        }

        return <<<HTML
<script data-no-extract="true"
        data-load-order="last"
        data-weline-panel-visitor-bootstrap="true">
(function (window, document) {
    'use strict';

    if (window.__WELINE_VISITOR_PANEL_LAZY_BOOTSTRAPPED__) {
        return;
    }
    window.__WELINE_VISITOR_PANEL_LAZY_BOOTSTRAPPED__ = true;

    var panelScriptUrl = {$panelScriptUrlJson};
    var loadingPromise = null;

    function visitorPanelApi() {
        var api = window.__WELINE_VISITOR_PANEL__;
        if (!api || typeof api.renderInto !== 'function' || typeof api.report !== 'function') {
            throw new Error('Weline Visitor panel bundle did not publish its API.');
        }
        return api;
    }

    function loadVisitorPanel() {
        if (window.__WELINE_VISITOR_PANEL__) {
            return Promise.resolve(visitorPanelApi());
        }
        if (loadingPromise) {
            return loadingPromise;
        }

        loadingPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = panelScriptUrl;
            script.async = false;
            script.setAttribute('data-no-extract', 'true');
            script.setAttribute('data-load-order', 'last');
            script.setAttribute('data-weline-panel-visitor-bundle', 'true');
            script.onload = function () {
                try {
                    resolve(visitorPanelApi());
                } catch (error) {
                    loadingPromise = null;
                    reject(error);
                }
            };
            script.onerror = function () {
                loadingPromise = null;
                script.remove();
                reject(new Error('Weline Visitor panel bundle could not be loaded.'));
            };
            (document.body || document.documentElement).appendChild(script);
        });

        return loadingPromise;
    }

    function reportProvider(options) {
        return loadVisitorPanel().then(function (api) {
            if (options && options.refresh && typeof api.refresh === 'function') {
                return Promise.resolve(api.refresh({
                    content: document.getElementById('dev-tool-content'),
                    searchArea: document.getElementById('dev-tool-search-area-visitor')
                })).then(function () {
                    return api.report();
                });
            }
            return api.report();
        });
    }

    var manifest = {
        id: 'visitor',
        title: '访问事件',
        order: 190,
        activate: function (context) {
            return loadVisitorPanel().then(function (api) {
                return api.renderInto(context && context.content, context);
            });
        },
        report: reportProvider
    };

    window.__WELINE_PANEL_TAB_QUEUE__ = window.__WELINE_PANEL_TAB_QUEUE__ || [];
    window.__WELINE_PANEL_REPORT_PROVIDERS__ = window.__WELINE_PANEL_REPORT_PROVIDERS__ || {};
    window.__WELINE_PANEL_REPORT_PROVIDERS__.visitor = reportProvider;
    if (window.WelinePanel && typeof window.WelinePanel.registerTab === 'function') {
        window.WelinePanel.registerTab(manifest);
    } else if (!window.__WELINE_PANEL_TAB_QUEUE__.some(function (item) {
        return item && item.id === 'visitor';
    })) {
        window.__WELINE_PANEL_TAB_QUEUE__.push(manifest);
    }
    if (window.WelinePanel && typeof window.WelinePanel.registerReportProvider === 'function') {
        window.WelinePanel.registerReportProvider('visitor', reportProvider);
    }
    window.__WELINE_VISITOR_PANEL_LAZY__ = { load: loadVisitorPanel };
})(window, document);
</script>
HTML;
    }

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
