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

    var api = {
        businessPayload: businessPayload,
        formatApiError: formatApiError,
        unwrapBusiness: unwrapBusiness,
        wrapAdminBridgeResult: wrapAdminBridgeResult
    };

    function attachToWeline() {
        global.WelineApiBusiness = api;
        if (!global.Weline) {
            global.Weline = {};
        }
        global.Weline.ApiBusiness = api;
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
