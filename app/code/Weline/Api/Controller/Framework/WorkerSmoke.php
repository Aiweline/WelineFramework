<?php
declare(strict_types=1);

namespace Weline\Api\Controller\Framework;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Response;

class WorkerSmoke extends FrontendController
{
    public function getIndex(): Response
    {
        if (Env::system('deploy', 'prod') !== 'dev') {
            return Response::text('Not found', 404);
        }

        return Response::html($this->html());
    }

    private function html(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Weline Frontend Worker API Smoke</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; padding: 24px; background: #0f172a; color: #dbeafe; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        pre { white-space: pre-wrap; word-break: break-word; background: #111827; border: 1px solid #334155; border-radius: 10px; padding: 16px; }
        .pass { color: #86efac; }
        .fail { color: #fca5a5; }
    </style>
    <script>
        window.DEV = true;
        window.WelineApiConfig = {
            workerUrl: '/Weline/Frontend/view/statics/js/weline-api-worker.js',
            endpoint: '/api/framework/query-bin',
            deployVersion: 'dev',
            workerBuildId: 'dev-smoke',
            requestTimeoutMs: 4000
        };
        window.__WelineThemeConfig = {
            env: { WELINE_ENV: 'DEV', DEV: true, PROD: false },
            modulesBaseUrl: '/Weline/Frontend/view/statics/js/weline-api',
            modulesConfig: {
                modules: {
                    api: {
                        paths: ['/Weline/Frontend/view/statics/js/weline-api.js'],
                        globalVar: 'WelineApiModule'
                    }
                },
                moduleAliases: {}
            },
            api: window.WelineApiConfig
        };
    </script>
    <script src="/Weline/Theme/view/theme/frontend/assets/js/theme.js?v=20260517-api-loader-2"></script>
</head>
<body>
    <h1>Weline Frontend Worker API Smoke</h1>
    <pre id="result">running</pre>
    <script>
        (async function () {
            const result = document.getElementById('result');
            const report = { ok: false, checks: {}, errors: [], debug: {} };
            const pass = (name, value) => { report.checks[name] = !!value; };
            const fail = (name, error) => {
                report.checks[name] = false;
                report.errors.push(name + ': ' + (error && error.message ? error.message : String(error)));
            };

            try {
                report.debug.before_resource = {
                    moduleFull: !!(window.WelineApiModule && window.WelineApiModule.__full),
                    moduleResourceType: typeof (window.WelineApiModule && window.WelineApiModule.resource),
                    moduleKeys: window.WelineApiModule ? Object.keys(window.WelineApiModule).slice(0, 20) : [],
                    welineApiResourceType: typeof (window.Weline && window.Weline.Api && window.Weline.Api.resource)
                };
                pass('api_loaded', !!(window.Weline && window.Weline.Api));

                const CartApi = await window.Weline.Api.resource('cart');
                pass('reserved_then_hidden', CartApi.then === undefined);
                pass('reserved_constructor_hidden', CartApi.constructor === undefined);
                pass('reserved_proto_not_in_proxy', !('__proto__' in CartApi));

                const cartCount = await CartApi.count({});
                pass('resource_cart_count', !!cartCount && !!cartCount.data && cartCount.data.success === true);

                const graph = await window.Weline.Api.graph([
                    { provider: 'cart', operation: 'count', params: {}, as: 'cartCount' }
                ]);
                pass('graph_cart_count', !!graph && !!graph.cartCount && !!graph.cartCount.data && graph.cartCount.data.success === true);

                try {
                    await window.Weline.Api.request('/api/rest/v1/weshop/cart/add');
                    pass('direct_request_rejected', false);
                } catch (error) {
                    pass('direct_request_rejected', true);
                }

                try {
                    await window.Weline.Api.stream('cart.count');
                    pass('stream_non_stream_operation_denied', false);
                } catch (error) {
                    pass('stream_non_stream_operation_denied', error && error.code === 'capability_denied');
                }

                report.ok = Object.keys(report.checks).length > 0 && Object.values(report.checks).every(Boolean);
            } catch (error) {
                fail('smoke_runtime', error);
            }

            result.className = report.ok ? 'pass' : 'fail';
            result.textContent = JSON.stringify(report, null, 2);
            window.__WELINE_WORKER_SMOKE__ = report;
        })();
    </script>
</body>
</html>
HTML;
    }
}
