(function (window) {
    'use strict';

    function appendLog(el, message) {
        el.textContent += '[' + new Date().toISOString() + '] ' + message + '\n';
        el.scrollTop = el.scrollHeight;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include',
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json();
        });
    }

    function relayWebhook(config, payload, logEl) {
        var inboundUrl = config.localInboundUrl || payload.local_inbound_url;
        if (!inboundUrl) {
            appendLog(logEl, 'Missing local inbound URL.');
            return Promise.resolve(false);
        }

        return fetch(payload.fetch_url, { credentials: 'include' })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success || !result.payload) {
                    throw new Error(result.message || 'fetch payload failed');
                }
                var body = atob(result.payload.raw_body || '');
                var headers = result.payload.headers || {};
                return fetch(inboundUrl, {
                    method: 'POST',
                    credentials: 'include',
                    headers: Object.assign({
                        'Content-Type': 'application/json',
                        'X-Dev-Relay-Endpoint': result.payload.endpoint_code || payload.endpoint_code
                    }, flattenHeaders(headers)),
                    body: JSON.stringify({
                        endpoint_code: result.payload.endpoint_code,
                        raw_body_b64: result.payload.raw_body,
                        headers: headers,
                        signature: result.payload.signature || ''
                    })
                }).then(function (response) {
                    return response.text().then(function (text) {
                        return {
                            ok: response.ok,
                            status: response.status,
                            text: text
                        };
                    });
                });
            })
            .then(function (replay) {
                appendLog(logEl, 'Replay ' + payload.event_code + ' => HTTP ' + replay.status + ' ' + replay.text);
                return postJson(config.ackUrl, {
                    session_code: config.sessionCode,
                    token: config.token,
                    event_code: payload.event_code,
                    success: replay.ok,
                    error: replay.ok ? '' : replay.text
                });
            })
            .catch(function (error) {
                appendLog(logEl, 'Relay failed: ' + String(error));
                return postJson(config.ackUrl, {
                    session_code: config.sessionCode,
                    token: config.token,
                    event_code: payload.event_code,
                    success: false,
                    error: String(error)
                });
            });
    }

    function flattenHeaders(headers) {
        var flat = {};
        Object.keys(headers || {}).forEach(function (key) {
            var value = headers[key];
            flat[key] = Array.isArray(value) ? String(value[0] || '') : String(value);
        });
        return flat;
    }

    window.WelinePaymentDevRelayConsole = {
        init: function (config) {
            var logEl = document.getElementById('dev-relay-sse-log');
            var statusEl = document.getElementById('dev-relay-sse-status');
            var source = null;
            var params = new URLSearchParams(window.location.search);
            if (params.get('stream_url')) {
                config.streamUrl = params.get('stream_url');
            }

            function connect() {
                if (source) {
                    source.close();
                }
                statusEl.textContent = 'Connecting SSE...';
                source = new EventSource(config.streamUrl);
                source.addEventListener('session.open', function (event) {
                    statusEl.textContent = 'SSE connected';
                    appendLog(logEl, 'session.open ' + event.data);
                });
                source.addEventListener('webhook.relay', function (event) {
                    var payload = JSON.parse(event.data || '{}');
                    appendLog(logEl, 'webhook.relay ' + (payload.provider_event_id || ''));
                    if (config.mode === 'local') {
                        relayWebhook(config, payload, logEl);
                    } else {
                        appendLog(logEl, 'online monitor only');
                    }
                });
                source.addEventListener('command.result', function (event) {
                    appendLog(logEl, 'command.result ' + event.data);
                });
                source.addEventListener('error', function () {
                    statusEl.textContent = 'SSE error / reconnecting';
                });
            }

            document.getElementById('dev-relay-sse-reconnect').addEventListener('click', connect);
            document.getElementById('dev-relay-sse-close').addEventListener('click', function () {
                if (source) {
                    source.close();
                }
                window.close();
            });

            connect();
        }
    };
})(window);
