/**
 * Storefront thin copy of Backend weline-api-business helpers.
 * Keep in sync with Weline_Backend::js/weline-api-business.js
 */
(function (global) {
    'use strict';

    function businessPayload(error) {
        var response = error && error.response ? error.response : null;
        var wrapper = response && response.data ? response.data : null;
        if (wrapper && wrapper.data && typeof wrapper.data === 'object' && !Array.isArray(wrapper.data)) {
            return wrapper.data;
        }
        if (wrapper && typeof wrapper === 'object' && (wrapper.success !== undefined || Array.isArray(wrapper.errors))) {
            return wrapper;
        }
        return null;
    }

    function formatApiError(error, fallback) {
        var business = businessPayload(error);
        if (business) {
            var details = [];
            if (Array.isArray(business.errors)) {
                business.errors.forEach(function (item) {
                    var text = String(item || '').trim();
                    if (text) details.push(text);
                });
            }
            var nestedResults = business.data && Array.isArray(business.data.results)
                ? business.data.results
                : (Array.isArray(business.results) ? business.results : []);
            nestedResults.forEach(function (result) {
                if (!result || typeof result !== 'object') return;
                if (Array.isArray(result.error_messages)) {
                    result.error_messages.forEach(function (item) {
                        var text = String(item || '').trim();
                        if (text) details.push(text);
                    });
                }
                if (result.message) {
                    var messageText = String(result.message || '').trim();
                    if (messageText && result.success === false) details.push(messageText);
                }
            });
            if (details.length) {
                return details.filter(function (item, index, list) {
                    return list.indexOf(item) === index;
                }).slice(0, 3).join('\n');
            }
            if (business.message) return String(business.message);
        }
        if (error && error.message) return String(error.message);
        return fallback ? String(fallback) : 'Request failed.';
    }

    function unwrapBusiness(response) {
        if (!response || typeof response !== 'object') {
            return response;
        }
        if (response.success !== undefined || response.code !== undefined) {
            return response;
        }
        if (response.ok !== undefined && response.data !== undefined && typeof response.data === 'object'
            && !Array.isArray(response.data)) {
            return response.data;
        }
        return response;
    }

    function wrapAdminBridgeResult(data) {
        var body = (data && typeof data === 'object' && !Array.isArray(data))
            ? data
            : { success: true, data: data };
        var ok = body.success !== false;
        var resp = {
            ok: ok,
            status: ok ? 200 : 400,
            json: function () {
                return Promise.resolve(body);
            },
            text: function () {
                return Promise.resolve(typeof body === 'string' ? body : JSON.stringify(body == null ? {} : body));
            }
        };
        Object.keys(body).forEach(function (key) {
            if (key === 'ok' || key === 'json' || key === 'text' || key === 'status') {
                return;
            }
            resp[key] = body[key];
        });
        return resp;
    }

    function adminRequest(resource, url, options) {
        options = options || {};
        var body = options.body;
        if (body && typeof FormData !== 'undefined' && body instanceof FormData) {
            var params = new URLSearchParams();
            body.forEach(function (value, key) {
                if (!(typeof File !== 'undefined' && value instanceof File)) {
                    params.append(key, String(value));
                }
            });
            body = params.toString();
        } else if (body && typeof body !== 'string') {
            try {
                body = JSON.stringify(body);
            } catch (error) {
                body = '';
            }
        }
        var method = options.method || 'POST';
        var headers = options.headers || {};
        var run = function (apiClient) {
            return apiClient.resource(String(resource || '')).adminRequest({
                url: url,
                method: method,
                headers: headers,
                body: body || ''
            });
        };
        var promise = (global.Weline && global.Weline.load)
            ? global.Weline.load('api').then(run)
            : Promise.resolve(run(global.Weline.Api));
        return promise.then(wrapAdminBridgeResult);
    }

    var api = {
        businessPayload: businessPayload,
        formatApiError: formatApiError,
        unwrapBusiness: unwrapBusiness,
        wrapAdminBridgeResult: wrapAdminBridgeResult,
        adminRequest: adminRequest
    };

    function attachToWeline() {
        global.WelineApiBusiness = api;
        if (!global.Weline) {
            global.Weline = {};
        }
        global.Weline.ApiBusiness = api;
        global.Weline.adminRequest = adminRequest;
    }

    attachToWeline();
    var reattachTries = 0;
    var reattachTimer = setInterval(function () {
        attachToWeline();
        reattachTries += 1;
        if (reattachTries >= 40) {
            clearInterval(reattachTimer);
        }
    }, 50);
})(typeof window !== 'undefined' ? window : globalThis);
