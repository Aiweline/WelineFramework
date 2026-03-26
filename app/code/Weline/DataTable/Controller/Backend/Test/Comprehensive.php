<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\DataTable\Service\BackendAdminPageService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_DataTable::datatable_test_comprehensive',
    'DataTable 综合测试',
    'mdi mdi-test-tube',
    'DataTable 综合测试页面',
    'Weline_DataTable::datatable_module'
)]
class Comprehensive extends BackendController
{
    private const FRONTEND_TEMPLATE_BASE = 'Weline_DataTable::templates/frontend/test/';

    public function __construct(
        private readonly BackendAdminPageService $backendAdminPageService
    ) {
    }

    #[Acl(
        'Weline_DataTable::test_comprehensive_index',
        '综合测试首页',
        'mdi mdi-view-grid-outline',
        'DataTable 综合测试导航页'
    )]
    public function index(): string
    {
        $this->layoutType = 'default.blank';

        $scenarios = $this->appendScenarioUrls($this->backendAdminPageService->getScenarioCatalog());
        $this->assign(array_merge(
            [
                'title' => (string) __('DataTable Comprehensive Test'),
                'dashboardUrl' => '../index',
                'docUrl' => '../index/doc',
                'tagTestUrl' => '../tag-test/index',
                'frontendDemoUrl' => $this->getUrl('datatable/test'),
                'backendBasePath' => $this->getBackendBasePath(),
                'demoInitUrl' => 'datatable/rest/v1/demo-table/init-data',
                'demoClearUrl' => 'datatable/rest/v1/demo-table/clear-data',
                'verifyTagsUrl' => 'datatable/backend/test/comprehensive/verify-tags',
                'scenarios' => $scenarios,
            ],
            $this->backendAdminPageService->getDashboardData()
        ));

        return (string) $this->fetch('Weline_DataTable::templates/Test/Comprehensive/index.phtml');
    }

    #[Acl('Weline_DataTable::test_comprehensive_basic', '基础表格', 'mdi mdi-table', '基础表格测试')]
    public function basic(): string
    {
        return $this->renderDemoPage('basic', 'Basic Table Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_join', '关联查询', 'mdi mdi-link-variant', '多模型关联测试')]
    public function join(): string
    {
        return $this->renderDemoPage('join', 'Joined Table Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_multi_model', '多模型查询', 'mdi mdi-table-multiple', '兼容旧路由的 JOIN 测试')]
    public function multiModel(): string
    {
        return $this->renderDemoPage('join', 'Multi-model Query Demo', 'multiModel');
    }

    #[Acl('Weline_DataTable::test_comprehensive_form', '独立表单', 'mdi mdi-form-select', '独立表单测试')]
    public function form(): string
    {
        return $this->renderDemoPage('form', 'Standalone Form Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_upload', '上传字段', 'mdi mdi-paperclip', '上传与字段类型测试')]
    public function upload(): string
    {
        return $this->renderDemoPage('upload', 'Upload Field Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_field_types', '字段类型兼容页', 'mdi mdi-form-textbox', '兼容旧路由的字段类型测试')]
    public function fieldTypes(): string
    {
        return $this->renderDemoPage('upload', 'Field Types Demo', 'fieldTypes');
    }

    #[Acl('Weline_DataTable::test_comprehensive_transaction', '事务联动', 'mdi mdi-database-sync', '事务保存测试')]
    public function transaction(): string
    {
        return $this->renderDemoPage('transaction', 'Transaction Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_dependency', '依赖顺序', 'mdi mdi-source-branch', '依赖顺序保存测试')]
    public function dependency(): string
    {
        return $this->renderDemoPage('dependency', 'Dependency Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_cascade', '级联删除', 'mdi mdi-delete-sweep-outline', '级联删除测试')]
    public function cascade(): string
    {
        return $this->renderDemoPage('cascade', 'Cascade Delete Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_performance', '自动生成', 'mdi mdi-speedometer', '自动生成和性能测试')]
    public function performance(): string
    {
        return $this->renderDemoPage('performance', 'Performance Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_auto_generation', '自动生成兼容页', 'mdi mdi-auto-fix', '兼容旧路由的自动生成测试')]
    public function autoGeneration(): string
    {
        return $this->renderDemoPage('performance', 'Auto Generation Demo', 'autoGeneration');
    }

    #[Acl('Weline_DataTable::test_comprehensive_filter', '过滤搜索', 'mdi mdi-filter-variant', '兼容旧路由的过滤测试')]
    public function filter(): string
    {
        return $this->renderDemoPage('basic', 'Filter Demo', 'filter');
    }

    #[Acl('Weline_DataTable::test_comprehensive_sorting', '排序分页', 'mdi mdi-sort', '兼容旧路由的排序分页测试')]
    public function sorting(): string
    {
        return $this->renderDemoPage('basic', 'Sorting and Pagination Demo', 'sorting');
    }

    #[Acl('Weline_DataTable::test_comprehensive_crud', 'CRUD 测试', 'mdi mdi-database-edit-outline', '兼容旧路由的 CRUD 测试')]
    public function crud(): string
    {
        return $this->renderDemoPage('basic', 'CRUD Demo', 'crud');
    }

    #[Acl('Weline_DataTable::test_comprehensive_inheritance', '属性继承', 'mdi mdi-layers-triple-outline', '属性继承验证页')]
    public function inheritance(): string
    {
        $this->layoutType = 'default.blank';

        return (string) $this->template(
            'Weline_DataTable::templates/Test/Comprehensive/inheritance.phtml',
            [
                'title' => (string) __('Attribute Inheritance Verification'),
                'backendBasePath' => $this->getBackendBasePath(),
                'dashboardUrl' => '../index',
                'comprehensiveUrl' => 'index',
                'tagTestUrl' => '../tag-test/index',
                'verifyTagsUrl' => 'datatable/backend/test/comprehensive/verify-tags',
            ]
        );
    }

    #[Acl('Weline_DataTable::test_comprehensive_verify_tags', '标签验证接口', 'mdi mdi-check-circle-outline', '标签验证 JSON 接口')]
    public function verifyTags(): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);

        return $response->renderJson([
            'success' => true,
            'message' => (string) __('Tag verification completed.'),
            'data' => $this->backendAdminPageService->getTagVerificationReport(),
        ]);
    }

    private function renderDemoPage(string $templateKey, string $pageTitle, ?string $pageKey = null): string
    {
        $this->layoutType = 'default.blank';
        $pageKey = $pageKey ?: $templateKey;

        return (string) $this->template(
            self::FRONTEND_TEMPLATE_BASE . $templateKey . '.phtml',
            [
                'page_title' => (string) __($pageTitle),
                'page_key' => $pageKey,
                'frontend_api_bootstrap' => $this->buildFrontendApiBootstrap(),
                'demo_links' => $this->buildBackendDemoLinks(),
            ]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $scenarios
     * @return array<int,array<string,mixed>>
     */
    private function appendScenarioUrls(array $scenarios): array
    {
        $result = [];
        foreach ($scenarios as $scenario) {
            $route = (string) ($scenario['route'] ?? 'index');
            $scenario['url'] = $this->toRouteSegment($route);
            $result[] = $scenario;
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function buildBackendDemoLinks(): array
    {
        return [
            'index' => 'index',
            'basic' => 'basic',
            'join' => 'join',
            'form' => 'form',
            'upload' => 'upload',
            'transaction' => 'transaction',
            'dependency' => 'dependency',
            'cascade' => 'cascade',
            'performance' => 'performance',
        ];
    }

    private function getBackendBasePath(): string
    {
        $path = (string) parse_url((string) $this->_url->getBackendUrl('/'), PHP_URL_PATH);
        return rtrim($path, '/');
    }

    private function toRouteSegment(string $value): string
    {
        $segment = preg_replace('/([a-z])([A-Z])/', '$1-$2', $value) ?: $value;
        return strtolower($segment);
    }

    private function buildFrontendApiBootstrap(): string
    {
        $frontendApiBase = rtrim((string) w_url('', [], 'frontend_api'), '/');
        $parts = parse_url($frontendApiBase);
        $frontendApiHost = $frontendApiBase;
        if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $frontendApiHost = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $frontendApiHost .= ':' . $parts['port'];
            }
        }
        $frontendApiArea = trim((string) parse_url($frontendApiBase, PHP_URL_PATH), '/');

        $apiHost = json_encode(rtrim($frontendApiHost, '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $apiArea = json_encode(trim($frontendApiArea, '/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<script>
    (function () {
        var defaultApiHost = {$apiHost};
        var defaultApiArea = {$apiArea};
        var currentPath = String(window.location && window.location.pathname || '');
        var apiHost = String(window.location && window.location.origin || '').replace(/\/+$/, '') || defaultApiHost;
        var apiArea = currentPath.indexOf('/@backend') === 0 ? '@api' : String(defaultApiArea || '').replace(/^\/+|\/+$/g, '');

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

                var eqMatch = target.match(/^(.*):eq\\((\\d+)\\)$/);
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
                var classes = String(className || '').split(/\\s+/).filter(Boolean);
                return this.each(function () {
                    this.classList.add.apply(this.classList, classes);
                });
            };
            JQueryLite.prototype.removeClass = function (className) {
                var classes = String(className || '').split(/\\s+/).filter(Boolean);
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
                var eventNames = String(eventName || '').split(/\\s+/).filter(Boolean);
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

        // The backend shell defines api helpers before this script runs, but on proxy-backed
        // E2E pages those helpers point at the target origin and cause cross-origin fetches.
        // We intentionally override them here with same-origin, proxy-aware versions.
        window.api = function (path, params) {
            var target = String(path || '').replace(/^\/+/, '');
            var url = String(window.site.api_host || window.location.origin).replace(/\/+$/, '');
            var area = String(window.site.api_area || '').replace(/^\/+|\/+$/g, '');

            if (area) {
                url += '/' + area;
            }
            if (target) {
                url += '/' + target;
            }

            if (params && typeof params === 'object') {
                var searchParams = new URLSearchParams();
                Object.keys(params).forEach(function (key) {
                    var value = params[key];
                    if (value === null || value === undefined) {
                        return;
                    }
                    searchParams.append(key, value);
                });

                var query = searchParams.toString();
                if (query) {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + query;
                }
            }

            return url;
        };

        window.frontend_api = window.api;

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
