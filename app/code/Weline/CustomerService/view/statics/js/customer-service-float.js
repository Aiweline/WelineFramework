/**
 * Customer service float widget boot (Weline_CustomerService).
 * Replaces the two inline <script> blocks in
 * view/templates/frontend/widgets/customer-service-float.phtml:
 *   - builds window.customerServiceConfig from the widget root data-* URLs
 *     plus the non-executable JSON block #cs-float-config
 *   - lazily loads js/customer-service.js after page load (3s) with a Weline.load
 *     path and a plain <script> fallback
 * Registered as module `customerServiceFloat`; the widget root declares data-weline-load.
 */
(function () {
    'use strict';

    var root = document.getElementById('customer-service-widget');
    var CONFIG = (function () {
        var el = document.getElementById('cs-float-config');
        if (!el) { return {}; }
        try { return JSON.parse(el.textContent || '{}') || {}; } catch (e) { return {}; }
    }());
    var data = root ? root.dataset : {};

    window.customerServiceConfig = {
        chatUrl: data.csChatUrl || '',
        bindUrl: data.csBindUrl || '',
        bindCaptchaEnabled: CONFIG.bindCaptchaEnabled === true,
        bindCaptchaChallengeUrl: data.csCaptchaUrl || '',
        html2canvasUrl: CONFIG.html2canvasUrl || '',
        modernScreenshotUrl: CONFIG.modernScreenshotUrl || '',
        customerId: CONFIG.customerId == null ? null : CONFIG.customerId,
        isLoggedIn: CONFIG.isLoggedIn === true,
        showGuestBindPrompt: CONFIG.showGuestBindPrompt === true,
        defaultCustomerLocale: CONFIG.defaultCustomerLocale || '',
        storefrontLocale: CONFIG.storefrontLocale || '',
        supportedLocales: CONFIG.supportedLocales || {},
        widgetTranslations: CONFIG.widgetTranslations || {}
    };

    function initCustomerServiceWidget() {
        if (typeof CustomerServiceWidget === 'undefined') {
            setTimeout(initCustomerServiceWidget, 100);
            return;
        }
        try {
            CustomerServiceWidget.init(window.customerServiceConfig);
        } catch (error) {
            console.error('[CustomerService] 初始化失败', error);
        }
    }

    var customerServiceJsVersion = CONFIG.jsVersion || '';
    var customerServiceJsHref = CONFIG.jsHref || '';
    var customerServiceScriptLoading = false;
    var customerServiceOpenAfterLoad = false;

    function waitForWelineLoad(attemptsLeft) {
        attemptsLeft = typeof attemptsLeft === 'number' ? attemptsLeft : 80;
        if (window.Weline && typeof window.Weline.load === 'function') {
            return Promise.resolve(window.Weline);
        }
        if (attemptsLeft <= 0) {
            return Promise.reject(new Error('[CustomerService] Weline.load unavailable'));
        }
        return new Promise(function (resolve, reject) {
            window.setTimeout(function () {
                waitForWelineLoad(attemptsLeft - 1).then(resolve).catch(reject);
            }, 50);
        });
    }

    function loadCustomerServiceScriptFallback() {
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-weline-cs-fallback="1"]');
            if (existing) {
                resolve();
                return;
            }
            var script = document.createElement('script');
            script.async = true;
            script.dataset.welineCsFallback = '1';
            script.src = customerServiceJsHref
                + (customerServiceJsHref.indexOf('?') >= 0 ? '&' : '?')
                + 'v=' + encodeURIComponent(customerServiceJsVersion);
            script.onload = function () { resolve(); };
            script.onerror = function () {
                reject(new Error('[CustomerService] fallback script load failed'));
            };
            document.head.appendChild(script);
        });
    }

    function loadCustomerServiceWidget(openAfterLoad) {
        customerServiceOpenAfterLoad = customerServiceOpenAfterLoad || Boolean(openAfterLoad);
        if (typeof CustomerServiceWidget !== 'undefined' && window.CustomerServiceWidget) {
            initCustomerServiceWidget();
            if (customerServiceOpenAfterLoad && typeof CustomerServiceWidget.toggleChat === 'function') {
                customerServiceOpenAfterLoad = false;
                CustomerServiceWidget.toggleChat();
            }
            return;
        }
        if (customerServiceScriptLoading) {
            return;
        }
        customerServiceScriptLoading = true;
        var afterLoad = function () {
            customerServiceScriptLoading = false;
            initCustomerServiceWidget();
            if (customerServiceOpenAfterLoad && typeof CustomerServiceWidget !== 'undefined'
                && typeof CustomerServiceWidget.toggleChat === 'function') {
                customerServiceOpenAfterLoad = false;
                CustomerServiceWidget.toggleChat();
            }
        };
        waitForWelineLoad()
            .then(function (weline) {
                return weline.load(
                    'customerService',
                    'Weline_CustomerService::js/customer-service.js?v=' + encodeURIComponent(customerServiceJsVersion)
                );
            })
            .catch(function () {
                return loadCustomerServiceScriptFallback();
            })
            .then(afterLoad)
            .catch(function (error) {
                customerServiceScriptLoading = false;
                console.error('[CustomerService] 模块加载失败', error);
            });
    }

    // 等网页完整 load 后再延迟加载，避免与正文抢带宽/主线程。
    var CS_POST_LOAD_DELAY_MS = CONFIG.postLoadDelayMs || 3000;

    function scheduleCustomerServiceWidget() {
        window.setTimeout(function () {
            loadCustomerServiceWidget(false);
        }, CS_POST_LOAD_DELAY_MS);
    }

    window.__WelineLoadCustomerServiceWidget = loadCustomerServiceWidget;
    var customerServiceChatButton = document.querySelector('[data-cs-toggle-chat]');
    if (customerServiceChatButton) {
        customerServiceChatButton.addEventListener('click', function (event) {
            if (typeof CustomerServiceWidget === 'undefined') {
                event.preventDefault();
                loadCustomerServiceWidget(true);
            }
        }, true);
    }

    if (document.readyState === 'complete') {
        scheduleCustomerServiceWidget();
    } else {
        window.addEventListener('load', scheduleCustomerServiceWidget, { once: true });
    }
}());
