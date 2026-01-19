/* eslint-disable no-restricted-globals */
const DEFAULT_CREDENTIALS = 'same-origin';
const TEXT_MIME_REGEXP = /^text\//i;

self.addEventListener('message', async (event) => {
    const payload = event.data || {};
    const { id, url, options = {} } = payload;

    if (!id || !url) {
        return;
    }

    try {
        const requestInit = buildRequestInit(options);
        const response = await fetch(url, requestInit);
        const headers = collectHeaders(response.headers);
        const body = await parseBody(response, headers['content-type'] ?? '');
        const maintenance = detectMaintenance(response, body);

        self.postMessage({
            id,
            ok: response.ok,
            status: response.status,
            statusText: response.statusText,
            headers,
            body,
            maintenance,
        });
    } catch (error) {
        self.postMessage({
            id,
            ok: false,
            status: 0,
            statusText: '',
            headers: {},
            body: null,
            maintenance: false,
            error: error instanceof Error ? error.message : String(error),
        });
    }
});

function buildRequestInit(options) {
    const init = {
        method: options.method || 'GET',
        credentials: options.credentials || DEFAULT_CREDENTIALS,
    };

    if (options.headers) {
        init.headers = options.headers;
    }

    if (options.body !== undefined) {
        init.body = options.body;
    }

    if (options.mode) {
        init.mode = options.mode;
    }

    if (options.cache) {
        init.cache = options.cache;
    }

    if (options.redirect) {
        init.redirect = options.redirect;
    }

    if (options.keepalive === true) {
        init.keepalive = true;
    }

    if (options.referrer) {
        init.referrer = options.referrer;
    }

    if (options.referrerPolicy) {
        init.referrerPolicy = options.referrerPolicy;
    }

    if (options.integrity) {
        init.integrity = options.integrity;
    }

    return init;
}

async function parseBody(response, contentType) {
    if (typeof contentType === 'string' && contentType.indexOf('application/json') !== -1) {
        try {
            return await response.json();
        } catch (error) {
            return {
                parse_error: true,
                message: error instanceof Error ? error.message : String(error),
            };
        }
    }

    if (TEXT_MIME_REGEXP.test(contentType)) {
        return await response.text();
    }

    return null;
}

function collectHeaders(responseHeaders) {
    const headers = {};
    responseHeaders.forEach((value, key) => {
        headers[key] = value;
    });
    return headers;
}

function detectMaintenance(response, body) {
    // 检测维护模式响应
    // 格式：{"success":false,"code":"maintenance","message":"系统正在升级，请稍后再试。","data":{"retry_after":60,"request_id":1768278381.756889}}
    if (response.status === 503) {
        return true;
    }

    if (body && typeof body === 'object') {
        const code = typeof body.code === 'string' ? body.code : (typeof body.status === 'string' ? body.status : '');
        if (code.toLowerCase() === 'maintenance') {
            return true;
        }
        if (body.maintenance === true) {
            return true;
        }
        if (body.data && body.data.maintenance === true) {
            return true;
        }
    }

    return false;
}



