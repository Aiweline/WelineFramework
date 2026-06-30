<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Test extends FrontendController
{
    private const TEMPLATE_BASE = 'Weline_DataTable::templates/frontend/test/';

    public function index(): string
    {
        return $this->renderPage('index', 'DataTable Frontend Demo');
    }

    public function basic(): string
    {
        return $this->renderPage('basic', 'Basic Table Demo');
    }

    public function join(): string
    {
        return $this->renderPage('join', 'Joined Table Demo');
    }

    public function form(): string
    {
        return $this->renderPage('form', 'Standalone Form Demo');
    }

    public function upload(): string
    {
        return $this->renderPage('upload', 'Upload Field Demo');
    }

    public function transaction(): string
    {
        return $this->renderPage('transaction', 'Transaction Demo');
    }

    public function dependency(): string
    {
        return $this->renderPage('dependency', 'Dependency Demo');
    }

    public function cascade(): string
    {
        return $this->renderPage('cascade', 'Cascade Delete Demo');
    }

    public function performance(): string
    {
        return $this->renderPage('performance', 'Performance Demo');
    }

    private function renderPage(string $page, string $title): string
    {
        $workerBase = rtrim((string) w_url('', [], 'frontend_api'), '/');
        $workerHost = $this->resolveApiHost($workerBase);
        $workerArea = trim((string) parse_url($workerBase, PHP_URL_PATH), '/');

        return $this->template(
            self::TEMPLATE_BASE . $page . '.phtml',
            [
                'page_title' => $title,
                'page_key' => $page,
                'worker_host' => $workerHost,
                'worker_area' => $workerArea,
                'worker_bootstrap' => $this->buildFrontendApiBootstrap($workerHost, $workerArea),
                'demo_links' => [
                    'index' => '/datatable/test',
                    'basic' => '/datatable/test/basic',
                    'join' => '/datatable/test/join',
                    'form' => '/datatable/test/form',
                    'upload' => '/datatable/test/upload',
                    'transaction' => '/datatable/test/transaction',
                    'dependency' => '/datatable/test/dependency',
                    'cascade' => '/datatable/test/cascade',
                    'performance' => '/datatable/test/performance',
                ],
            ]
        );
    }

    private function resolveApiHost(string $frontendApiBase): string
    {
        $parts = parse_url($frontendApiBase);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $frontendApiBase;
        }

        $host = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        return $host;
    }

    private function buildFrontendApiBootstrap(string $frontendApiHost, string $frontendApiArea): string
    {
        $apiHost = json_encode(rtrim($frontendApiHost, '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $apiArea = json_encode(trim($frontendApiArea, '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<script>
    (function () {
        var apiHost = {$apiHost};
        var apiArea = {$apiArea};

        if (typeof window.$ !== 'function') {
            var toCamelCase = function (value) {
                return String(value || '').replace(/-([a-z])/g, function (_, letter) {
                    return letter.toUpperCase();
                });
            };
            var queryWithEq = function (root, selector) {
                var target = String(selector || '').trim();
                if (!target) {
                    return [];
                }

                var eqMatch = target.match(/^(.*):eq\((\d+)\)$/);
                if (eqMatch) {
                    var baseSelector = eqMatch[1].trim();
                    var index = parseInt(eqMatch[2], 10);
                    var baseNodes = baseSelector ? Array.from(root.querySelectorAll(baseSelector)) : [];
                    return baseNodes[index] ? [baseNodes[index]] : [];
                }

                return Array.from(root.querySelectorAll(target));
            };
            var JQueryLite = function (elements) {
                this.elements = Array.isArray(elements) ? elements.filter(Boolean) : [];
                this.length = this.elements.length;
            };
            JQueryLite.prototype.each = function (callback) {
                this.elements.forEach(function (element, index) {
                    callback.call(element, index, element);
                });
                return this;
            };
            JQueryLite.prototype.find = function (selector) {
                var found = [];
                this.each(function () {
                    found = found.concat(queryWithEq(this, selector));
                });
                return new JQueryLite(found);
            };
            JQueryLite.prototype.addClass = function (className) {
                var classes = String(className || '').split(/\s+/).filter(Boolean);
                return this.each(function () {
                    this.classList.add.apply(this.classList, classes);
                });
            };
            JQueryLite.prototype.removeClass = function (className) {
                var classes = String(className || '').split(/\s+/).filter(Boolean);
                return this.each(function () {
                    this.classList.remove.apply(this.classList, classes);
                });
            };
            JQueryLite.prototype.toggleClass = function (className, force) {
                return this.each(function () {
                    this.classList.toggle(className, force);
                });
            };
            JQueryLite.prototype.hasClass = function (className) {
                return !!(this.length && this.elements[0].classList.contains(className));
            };
            JQueryLite.prototype.text = function (value) {
                if (typeof value === 'undefined') {
                    return this.length ? this.elements[0].textContent : '';
                }
                return this.each(function () {
                    this.textContent = value;
                });
            };
            JQueryLite.prototype.html = function (value) {
                if (typeof value === 'undefined') {
                    return this.length ? this.elements[0].innerHTML : '';
                }
                return this.each(function () {
                    this.innerHTML = value;
                });
            };
            JQueryLite.prototype.css = function (name, value) {
                if (typeof value === 'undefined') {
                    return this.length ? getComputedStyle(this.elements[0])[name] : '';
                }
                return this.each(function () {
                    this.style[name] = value;
                });
            };
            JQueryLite.prototype.data = function (name) {
                if (!this.length) {
                    return undefined;
                }
                return this.elements[0].dataset[toCamelCase(name)];
            };
            JQueryLite.prototype.val = function (value) {
                if (typeof value === 'undefined') {
                    return this.length ? this.elements[0].value : undefined;
                }
                return this.each(function () {
                    this.value = value;
                });
            };
            JQueryLite.prototype.attr = function (name, value) {
                if (typeof value === 'undefined') {
                    return this.length ? this.elements[0].getAttribute(name) : undefined;
                }
                return this.each(function () {
                    this.setAttribute(name, value);
                });
            };
            JQueryLite.prototype.prop = function (name, value) {
                if (typeof value === 'undefined') {
                    return this.length ? this.elements[0][name] : undefined;
                }
                return this.each(function () {
                    this[name] = value;
                });
            };
            JQueryLite.prototype.is = function (selector) {
                if (!this.length) {
                    return false;
                }
                if (selector === ':checked') {
                    return !!this.elements[0].checked;
                }
                return this.elements[0].matches(selector);
            };
            JQueryLite.prototype.closest = function (selector) {
                if (!this.length) {
                    return new JQueryLite([]);
                }
                return new JQueryLite([this.elements[0].closest(selector)]);
            };
            JQueryLite.prototype.parent = function () {
                if (!this.length || !this.elements[0].parentElement) {
                    return new JQueryLite([]);
                }
                return new JQueryLite([this.elements[0].parentElement]);
            };
            JQueryLite.prototype.width = function () {
                return this.length ? this.elements[0].getBoundingClientRect().width : 0;
            };
            JQueryLite.prototype.hide = function () {
                return this.css('display', 'none');
            };
            JQueryLite.prototype.show = function () {
                return this.css('display', '');
            };
            JQueryLite.prototype.remove = function () {
                return this.each(function () {
                    this.remove();
                });
            };
            JQueryLite.prototype.append = function (content) {
                return this.each(function () {
                    var host = this;
                    if (typeof content === 'string') {
                        host.insertAdjacentHTML('beforeend', content);
                        return;
                    }
                    if (content instanceof JQueryLite) {
                        content.each(function () {
                            host.appendChild(this.cloneNode(true));
                        });
                        return;
                    }
                    if (content instanceof Node) {
                        host.appendChild(content.cloneNode(true));
                    }
                });
            };
            JQueryLite.prototype.focus = function () {
                if (this.length && typeof this.elements[0].focus === 'function') {
                    this.elements[0].focus();
                }
                return this;
            };
            JQueryLite.prototype.on = function (eventName, selector, handler) {
                if (typeof selector === 'function') {
                    handler = selector;
                    selector = null;
                }
                if (typeof handler !== 'function') {
                    return this;
                }
                var eventNames = String(eventName || '').split(/\s+/).filter(Boolean);
                return this.each(function () {
                    var element = this;
                    eventNames.forEach(function (singleEventName) {
                        element.addEventListener(singleEventName, function (event) {
                            if (!selector) {
                                handler.call(event.currentTarget, event);
                                return;
                            }
                            var matchedTarget = event.target.closest(selector);
                            if (matchedTarget && element.contains(matchedTarget)) {
                                handler.call(matchedTarget, event);
                            }
                        });
                    });
                });
            };
            JQueryLite.prototype.off = function () {
                return this;
            };
            JQueryLite.prototype.modal = function (action) {
                return this.each(function () {
                    if (action === 'show') {
                        this.style.display = 'block';
                        this.classList.add('show');
                    } else if (action === 'hide') {
                        this.style.display = 'none';
                        this.classList.remove('show');
                    }
                });
            };

            window.$ = window.jQuery = function (input) {
                if (input instanceof JQueryLite) {
                    return input;
                }
                if (typeof input === 'string') {
                    var trimmed = input.trim();
                    if (trimmed.charAt(0) === '<') {
                        var template = document.createElement('template');
                        template.innerHTML = trimmed;
                        return new JQueryLite(template.content.firstElementChild ? [template.content.firstElementChild] : []);
                    }
                    return new JQueryLite(queryWithEq(document, trimmed));
                }
                if (input === document || input === window || input instanceof Element || input instanceof Document) {
                    return new JQueryLite([input]);
                }
                if (input && typeof input.length === 'number') {
                    return new JQueryLite(Array.from(input));
                }
                return new JQueryLite([]);
            };
        }

        window.site = window.site || {};
        if (apiHost) {
            window.site.host = window.site.host || apiHost;
            window.site.api_host = apiHost;
        }
        if (apiArea) {
            window.site.api_area = apiArea;
        }

        window.__WelineThemeConfig = Object.assign(window.__WelineThemeConfig || {}, {
            modulesConfigUrl: '/Weline/Frontend/view/statics/base/weline.modules.js',
            modulesBaseUrl: '/Weline/Frontend/view/statics/js/weline-api',
            api: {
                workerUrl: '/Weline/Frontend/view/statics/js/weline-api-worker.js',
                endpoint: '/api/framework/query-bin',
                queryBinUrl: '/api/framework/query-bin'
            }
        });
        window.modulesConfigUrl = window.__WelineThemeConfig.modulesConfigUrl;
        window.WelineApiConfig = Object.assign(window.WelineApiConfig || {}, window.__WelineThemeConfig.api);

        if (!document.getElementById('weline-theme-js')) {
            var themeScript = document.createElement('script');
            themeScript.id = 'weline-theme-js';
            themeScript.src = '/Weline/Theme/view/theme/frontend/assets/js/theme.js?v=20260517-api-loader-2';
            themeScript.async = false;
            themeScript.defer = false;
            document.head.appendChild(themeScript);
        }

        var patchDataTableManager = function () {
            if (!window.DataTableManager || window.DataTableManager.__frontendCompatPatched) {
                return !!(window.DataTableManager && window.DataTableManager.__frontendCompatPatched);
            }

            var wrapEventBinder = function (methodName) {
                var originalMethod = window.DataTableManager[methodName];
                if (typeof originalMethod !== 'function') {
                    return;
                }

                window.DataTableManager[methodName] = function (instance, element) {
                    if (element && typeof element.on !== 'function' && typeof window.$ === 'function') {
                        element = window.$(element);
                    }
                    return originalMethod.call(this, instance, element);
                };
            };

            wrapEventBinder('bindHeaderEvents');
            wrapEventBinder('bindRowEvents');
            wrapEventBinder('bindPaginationEvents');

            window.DataTableManager.__frontendCompatPatched = true;
            return true;
        };

        var compatTimer = window.setInterval(function () {
            if (patchDataTableManager()) {
                window.clearInterval(compatTimer);
            }
        }, 50);

        window.setTimeout(function () {
            window.clearInterval(compatTimer);
            patchDataTableManager();
        }, 5000);
    })();
</script>
HTML;
    }
}
